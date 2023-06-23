<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Filter\Ast\Concatenator;
use Terminal42\Loupe\Internal\Filter\Ast\Filter;
use Terminal42\Loupe\Internal\Filter\Ast\GeoDistance;
use Terminal42\Loupe\Internal\Filter\Ast\Group;
use Terminal42\Loupe\Internal\Filter\Ast\Node;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Terminal42\Loupe\Internal\Search\Sorting\GeoPoint;
use Terminal42\Loupe\Internal\Tokenizer\TokenCollection;
use Terminal42\Loupe\Internal\Util;
use Terminal42\Loupe\SearchParameters;
use voku\helper\UTF8;

class Searcher
{
    public const CTE_TERM_DOCUMENT_MATCHES = '_cte_term_document_matches';

    public const CTE_TERM_MATCHES = '_cte_term_matches';

    /**
     * @var array<string, array{'cols': array, 'sql': string}>
     */
    private array $CTEs = [];

    private string $id;

    private QueryBuilder $queryBuilder;

    private Sorting $sorting;

    private ?TokenCollection $tokens = null;

    public function __construct(
        private Engine $engine,
        private Parser $filterParser,
        private SearchParameters $searchParameters
    ) {
        $this->sorting = Sorting::fromArray($this->searchParameters->getSort(), $this->engine);
        $this->id = uniqid('lqi', true);
    }

    public function fetchResult(): array
    {
        $start = (int) floor(microtime(true) * 1000);

        $this->queryBuilder = $this->engine->getConnection()
            ->createQueryBuilder();

        $tokens = $this->getTokens();

        $this->selectTotalHits();
        $this->selectDocuments();
        $this->filterDocuments();
        $this->searchDocuments($tokens);
        $this->sortDocuments();
        $this->limitPagination();

        $showAllAttributes = ['*'] === $this->searchParameters->getAttributesToRetrieve();
        $attributesToRetrieve = array_flip($this->searchParameters->getAttributesToRetrieve());

        $hits = [];

        foreach ($this->query()->iterateAssociative() as $result) {
            $document = Util::decodeJson($result['document']);

            if (array_key_exists(GeoPoint::DISTANCE_ALIAS, $result)) {
                $document['_geoDistance'] = (int) round($result[GeoPoint::DISTANCE_ALIAS]);
            }

            $hit = $showAllAttributes ? $document : array_intersect_key($document, $attributesToRetrieve);

            $this->highlight($hit, $tokens);

            $hits[] = $hit;
        }

        $totalHits = $result['totalHits'] ?? 0;
        $totalPages = (int) ceil($totalHits / $this->searchParameters->getHitsPerPage());
        $end = (int) floor(microtime(true) * 1000);

        return [
            'hits' => $hits,
            'query' => $this->createAnalyzedQuery($tokens),
            'processingTimeMs' => $end - $start,
            'hitsPerPage' => $this->searchParameters->getHitsPerPage(),
            'page' => $this->searchParameters->getPage(),
            'totalPages' => $totalPages,
            'totalHits' => $totalHits,
        ];
    }

    public function getCTEs(): array
    {
        return $this->CTEs;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function getQueryId(): string
    {
        return $this->id;
    }

    public function getTokens(): TokenCollection
    {
        if ($this->tokens instanceof TokenCollection) {
            return $this->tokens;
        }

        if ($this->searchParameters->getQuery() === '') {
            return $this->tokens = new TokenCollection();
        }

        return $this->tokens = $this->engine->getTokenizer()
            ->tokenize($this->searchParameters->getQuery())
        ;
    }

    private function addTermDocumentMatchesCTE(): void
    {
        // No term matches CTE -> no term document matches CTE
        if (! isset($this->CTEs[self::CTE_TERM_MATCHES])) {
            return;
        }

        $termsDocumentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect($termsDocumentsAlias . '.document');
        $cteSelectQb->addSelect($termsDocumentsAlias . '.ntf * ' . sprintf('(SELECT idf FROM %s WHERE td.term=id)', self::CTE_TERM_MATCHES));
        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, $termsDocumentsAlias);
        $cteSelectQb->andWhere(sprintf($termsDocumentsAlias . '.term IN (SELECT id FROM %s)', self::CTE_TERM_MATCHES));
        $cteSelectQb->addOrderBy($termsDocumentsAlias . '.document');
        $cteSelectQb->addOrderBy($termsDocumentsAlias . '.term');

        $this->CTEs[self::CTE_TERM_DOCUMENT_MATCHES]['cols'] = ['document', 'tfidf'];
        $this->CTEs[self::CTE_TERM_DOCUMENT_MATCHES]['sql'] = $cteSelectQb->getSQL();
    }

    private function addTermMatchesCTE(TokenCollection $tokenCollection): void
    {
        if ($tokenCollection->empty()) {
            return;
        }

        $termsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect($termsAlias . '.id');
        $cteSelectQb->addSelect($termsAlias . '.idf');
        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS, $termsAlias);

        $ors = [];

        foreach ($tokenCollection->allTokensWithVariants() as $term) {
            $ors[] = $this->createWherePartForTerm($term);
        }

        $cteSelectQb->where('(' . implode(') OR (', $ors) . ')');
        $cteSelectQb->orderBy($termsAlias . '.id');

        $this->CTEs[self::CTE_TERM_MATCHES]['cols'] = ['id', 'idf'];
        $this->CTEs[self::CTE_TERM_MATCHES]['sql'] = $cteSelectQb->getSQL();
    }

    private function createAnalyzedQuery(TokenCollection $tokens): string
    {
        $lastToken = $tokens->last();

        if ($lastToken === null) {
            return $this->searchParameters->getQuery();
        }

        return mb_substr($this->searchParameters->getQuery(), 0, $lastToken->getStartPosition() + $lastToken->getLength());
    }

    private function createSubQueryForMultiAttribute(Filter $node): string
    {
        $qb = $this->engine->getConnection()
            ->createQueryBuilder();
        $qb
            ->select('document')
            ->from(
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)
            )
            ->innerJoin(
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                sprintf(
                    '%s.attribute=%s AND %s.id = %s.attribute',
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->queryBuilder->createNamedParameter($node->attribute),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            )
        ;

        $column = is_float($node->value) ? 'numeric_value' : 'string_value';

        $qb->andWhere(
            sprintf(
                '%s.%s %s %s',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                $column,
                $node->operator->value,
                $this->queryBuilder->createNamedParameter($node->value)
            )
        );

        return $qb->getSQL();
    }

    private function createWherePartForTerm(string $term): string
    {
        $termParameter = $this->queryBuilder->createNamedParameter($term);
        $levenshteinDistance = $this->engine->getConfiguration()
            ->getTypoTolerance()
            ->getLevenshteinDistanceForTerm($term);

        $where = [];

        if ($levenshteinDistance === 0) {
            /*
             * WHERE
             *     term = '<term>'
             */
            $where[] = sprintf(
                '%s.term = %s',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $termParameter
            );
        } else {
            /*
             * WHERE
             *     term = '<term>'
             *     OR
             *     (
             *         state IN (:states)
             *         AND
             *         LENGTH(term) >= <term> - <lev-distance>
             *         AND
             *         LENGTH(term) <= <term> + <lev-distance>
             *         AND
             *         max_levenshtein(<term>, term, <distance>)
             *       )
             */
            $where[] = sprintf(
                '%s.term = %s',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $termParameter
            );
            $where[] = 'OR';
            $where[] = '(';
            $where[] = sprintf(
                '%s.state IN (%s)',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                implode(',', $this->engine->getStateSetIndex()->findMatchingStates($term, $levenshteinDistance))
            );
            $where[] = 'AND';
            $where[] = sprintf(
                '%s.length >= %d',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                UTF8::strlen($term) - 1
            );
            $where[] = 'AND';
            $where[] = sprintf(
                '%s.length <= %d',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                UTF8::strlen($term) + 1
            );
            $where[] = 'AND';
            $where[] = sprintf(
                'max_levenshtein(%s, %s.term, %d)',
                $termParameter,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $levenshteinDistance
            );
            $where[] = ')';
        }

        return implode(' ', $where);
    }

    private function filterDocuments(): void
    {
        if ($this->searchParameters->getFilter() === '') {
            return;
        }

        $ast = $this->filterParser->getAst(
            $this->searchParameters->getFilter(),
            $this->engine->getConfiguration()->getFilterableAttributes()
        );

        $whereStatement = [];

        foreach ($ast->getNodes() as $node) {
            $this->handleFilterAstNode($node, $whereStatement);
        }

        $this->queryBuilder->andWhere(implode(' ', $whereStatement));
    }

    private function handleFilterAstNode(Node $node, array &$whereStatement): void
    {
        $documentAlias = $this->engine->getIndexInfo()
            ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        if ($node instanceof Group) {
            $whereStatement[] = '(';
            foreach ($node->getChildren() as $child) {
                $this->handleFilterAstNode($child, $whereStatement);
            }
            $whereStatement[] = ')';
        }

        if ($node instanceof Filter) {
            // Multi filterable need a sub query
            if (in_array($node->attribute, $this->engine->getIndexInfo()->getMultiFilterableAttributes(), true)) {
                $whereStatement[] = $documentAlias . '.id IN (';
                $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                $whereStatement[] = ')';

            // Single attributes are on the document itself
            } else {
                $whereStatement[] = $documentAlias . '.' . $node->attribute;
                $whereStatement[] = $node->operator->value;
                $whereStatement[] = $this->queryBuilder->createNamedParameter($node->value);
            }
        }

        if ($node instanceof GeoDistance) {
            // Start a group
            $whereStatement[] = '(';

            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            $bounds = $node->getBbox();

            // Latitude
            $whereStatement[] = $documentAlias . '._geo_lat';
            $whereStatement[] = '>=';
            $whereStatement[] = $bounds->getSouth();
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '._geo_lat';
            $whereStatement[] = '<=';
            $whereStatement[] = $bounds->getNorth();

            // Longitude
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '._geo_lng';
            $whereStatement[] = '>=';
            $whereStatement[] = $bounds->getWest();
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '._geo_lng';
            $whereStatement[] = '<=';
            $whereStatement[] = $bounds->getEast();

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $whereStatement[] = 'AND';
            $whereStatement[] = sprintf(
                'geo_distance(%f, %f, %s, %s)',
                $node->lat,
                $node->lng,
                $documentAlias . '._geo_lat',
                $documentAlias . '._geo_lng',
            );
            $whereStatement[] = '<=';
            $whereStatement[] = $node->distance;

            // End group
            $whereStatement[] = ')';
        }

        if ($node instanceof Concatenator) {
            $whereStatement[] = $node->getConcatenator();
        }
    }

    private function highlight(array &$hit, TokenCollection $tokenCollection)
    {
        if ($this->searchParameters->getAttributesToHighlight() === [] && ! $this->searchParameters->showMatchesPosition()) {
            return;
        }

        $formatted = $hit;
        $matchesPosition = [];

        $highlightAllAttributes = ['*'] === $this->searchParameters->getAttributesToHighlight();
        $attributesToHighlight = $highlightAllAttributes ?
            $this->engine->getConfiguration()->getSearchableAttributes() :
            $this->searchParameters->getAttributesToHighlight()
        ;

        foreach ($this->engine->getConfiguration()->getSearchableAttributes() as $attribute) {
            // Do not include any attribute not required by the result (limited by attributesToRetrieve)
            if (! isset($formatted[$attribute])) {
                continue;
            }

            $highlightResult = $this->engine->getHighlighter()
                ->highlight($formatted[$attribute], $tokenCollection);

            if (in_array($attribute, $attributesToHighlight, true)) {
                $formatted[$attribute] = $highlightResult->getHighlightedText();
            }

            if ($this->searchParameters->showMatchesPosition() && $highlightResult->getMatches() !== []) {
                $matchesPosition[$attribute] = $highlightResult->getMatches();
            }
        }

        if ($attributesToHighlight !== []) {
            $hit['_formatted'] = $formatted;
        }

        if ($matchesPosition !== []) {
            $hit['_matchesPosition'] = $matchesPosition;
        }
    }

    private function limitPagination(): void
    {
        $this->queryBuilder->setFirstResult(
            ($this->searchParameters->getPage() - 1) * $this->searchParameters->getHitsPerPage()
        );
        $this->queryBuilder->setMaxResults($this->searchParameters->getHitsPerPage());
    }

    private function query(): Result
    {
        $queryParts = [];

        if ($this->CTEs !== []) {
            $queryParts[] = 'WITH';
            foreach ($this->CTEs as $name => $config) {
                $queryParts[] = sprintf(
                    '%s (%s) AS (%s)',
                    $name,
                    implode(',', $config['cols']),
                    $config['sql']
                );
                $queryParts[] = ',';
            }

            array_pop($queryParts);
        }

        $queryParts[] = $this->queryBuilder->getSQL();

        return $this->engine->getConnection()->executeQuery(
            implode(' ', $queryParts),
            $this->queryBuilder->getParameters(),
            $this->queryBuilder->getParameterTypes(),
        );
    }

    private function searchDocuments(TokenCollection $tokenCollection): void
    {
        $this->addTermMatchesCTE($tokenCollection);
        $this->addTermDocumentMatchesCTE();

        if (! isset($this->CTEs[self::CTE_TERM_DOCUMENT_MATCHES])) {
            return;
        }

        $this->queryBuilder->andWhere(sprintf(
            '%s.id IN (SELECT DISTINCT document FROM %s)',
            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
            self::CTE_TERM_DOCUMENT_MATCHES
        ));
    }

    private function selectDocuments(): void
    {
        $this->queryBuilder
            ->addSelect($this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.document')
            ->from(
                IndexInfo::TABLE_NAME_DOCUMENTS,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)
            )
        ;
    }

    private function selectTotalHits(): void
    {
        $this->queryBuilder->addSelect('COUNT() OVER() AS totalHits');
    }

    private function sortDocuments(): void
    {
        $this->sorting->applySorters($this);
    }
}
