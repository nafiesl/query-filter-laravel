<?php

namespace LIQRGV\QueryFilter;

use HaydenPierce\ClassFinder\ClassFinder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Lumen\Routing\Controller;
use LIQRGV\QueryFilter\Exception\ModelNotFoundException;
use LIQRGV\QueryFilter\Exception\NotModelException;
use LIQRGV\QueryFilter\Struct\BuilderStruct;
use LIQRGV\QueryFilter\Struct\FilterStruct;
use LIQRGV\QueryFilter\Struct\ModelBuilderStruct;
use LIQRGV\QueryFilter\Struct\QueryBuilderStruct;
use LIQRGV\QueryFilter\Struct\SortStruct;

class RequestParser
{

    protected static $ALLOWED_OPERATOR = [
        "=",
        "!=",
        ">",
        "<",
        "is",
        "!is",
        "in",
        "!in",
        "between",
    ];
    /**
     * @var string
     */
    protected $tableName;
    /**
     * @var string
     */
    protected $modelName;
    /**
     * @var array
     */
    private $modelNamespaces;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var int|null
     */
    protected $pageLimit;

    public function __construct(Request $request)
    {
        $requestParserConfig = Config::get('request_parser');
        if (is_null($requestParserConfig) || empty($requestParserConfig)) {
            $this->modelNamespaces = ["App\\Models"];
        } else {
            $this->modelNamespaces = $requestParserConfig['model_namespaces'];
        }

        $this->request = $request;
    }

    public function setModel(string $modelName): RequestParser
    {
        $this->modelName = $modelName;

        return $this;
    }

    public function setTable($tableName): RequestParser
    {
        $this->tableName = $tableName;

        return $this;
    }

    public function setPageLimit(int $pageLimit): RequestParser
    {
        $this->pageLimit = $pageLimit;

        return $this;
    }

    public function getBuilder()
    {
        $modelBuilderStruct = $this->createModelBuilderStruct($this->request);
        $queryBuilder = $this->createModelQuery($modelBuilderStruct->baseModelName);

        $builder = $this->applyFilter($queryBuilder, $modelBuilderStruct->filters);
        $builder = $this->applySorter($builder, $modelBuilderStruct->sorter);
        $builder = $this->applyPaginator($builder, $modelBuilderStruct->paginator);

        return $builder;
    }

    private function createModelBuilderStruct(Request $request): ModelBuilderStruct
    {
        $defaultPageLimit = $this->pageLimit ?: Config::get('query_filter.default_page_limit');
        $queryParam = $request->query;
        $filterQuery = $queryParam->get('filter') ?? [];
        $sortQuery = $queryParam->get('sort') ?? null;
        $limitQuery = $queryParam->get('limit', $defaultPageLimit);
        $offsetQuery = $queryParam->get('offset') ?? 0;

        $baseModelName = $this->getBaseModelName($request);
        $filters = $this->parseFilter($filterQuery);
        $sorter = $this->parseSorter($sortQuery);
        $paginator = $this->parsePaginator($limitQuery, $offsetQuery);

        return new ModelBuilderStruct($baseModelName, $filters, $sorter, $paginator);
    }

    private function getBaseModelName(Request $request): string
    {
        if ($this->modelName) {
            return $this->modelName;
        }

        if ($this->tableName) {
            return $this->tableName;
        }

        $modelCandidates = [];
        $route = $request->route();
        $controller = $this->getControllerFromRoute($route);
        if ($controller) {
            $stringToRemove = "controller";
            $className = class_basename($controller);
            $maybeBaseModel = substr_replace($className, '', strrpos(strtolower($className), $stringToRemove), strlen($stringToRemove));
            $modelCandidates[] = $maybeBaseModel;

            $modelName = $this->getModelFromNamespaces($maybeBaseModel, $this->modelNamespaces);
            if ($modelName) {
                return $modelName;
            }
        }

        $exploded = explode("/", $request->getRequesturi());
        $lastURISegment = strtolower(end($exploded));
        $lastURINoQuery = current(explode("?", $lastURISegment, 2));
        $camelizeURI = str_replace('_', '', ucwords($lastURINoQuery, '_'));
        $modelCandidates[] = $camelizeURI;

        $modelName = $this->getModelFromNamespaces($camelizeURI, $this->modelNamespaces);
        if ($modelName) {
            return $modelName;
        }

        $errorMessage = "Model not found after looking on ";
        $searchPath = [];
        foreach ($modelCandidates as $candidate) {
            foreach ($this->modelNamespaces as $modelNamespace) {
                $searchPath[] = $modelNamespace . '\\' . $candidate;
            }
        }

        $errorMessage .= join(', ', $searchPath);

        throw new ModelNotFoundException($errorMessage);
    }

    private function parseFilter(array $filterQuery = []): array
    {
        $filters = [];

        if (is_array($filterQuery)) {
            foreach ($filterQuery as $key => $operatorValuePairs) {
                if (is_array($operatorValuePairs)) {
                    foreach ($operatorValuePairs as $operator => $value) {
                        if (in_array($operator, static::$ALLOWED_OPERATOR)) {
                            $filters[] = new FilterStruct($key, $operator, $value);
                        }
                    }
                }
            }
        }

        return $filters;
    }

    private function parseSorter(?string $sortQuery): ?array
    {
        if(is_null($sortQuery)) {
            return [];
        }

        $sortStructs = [];

        $fieldPattern = "/^\-?([a-zA-z\_]+)$/";
        $splitedSortQuery = explode(",", $sortQuery);

        foreach ($splitedSortQuery as $singleSortQuery) {
            if(preg_match($fieldPattern, $singleSortQuery, $match)) {
                $fieldName = $match[1];
                $direction = $singleSortQuery[0] == "-" ? "DESC" : "ASC";

                $sortStructs[] = new SortStruct($fieldName, $direction);
            }
        }

        return $sortStructs;
    }

    private function parsePaginator($limitQuery, $offsetQuery){
        return [
            "limit" => $limitQuery,
            "offset" => $offsetQuery
        ];
    }

    private function getModelFromNamespaces(string $modelName, array $modelNamespaces)
    {
        foreach ($modelNamespaces as $modelNamespace) {
            $classes = ClassFinder::getClassesInNamespace($modelNamespace);

            foreach ($classes as $class) {
                if ($class == $modelNamespace . '\\' . $modelName) {
                    return $class;
                }
            }
        }

        return null;
    }

    private function applyFilter($builder, array $filters)
    {
        foreach ($filters as $filterStruct) {
            $builder = $filterStruct->apply($builder);
        }

        return $builder;
    }

    private function applySorter($builder, array $sorter)
    {
        if(empty($sorter)) {
            return $builder;
        }

        foreach ($sorter as $sort) {
            $builder = $builder->orderBy($sort->fieldName, $sort->direction);
        }

        return $builder;
    }

    private function applyPaginator($builder, array $paginator)
    {
        if ($paginator['limit']){
            return $builder->limit($paginator['limit'])->offset($paginator['offset']);
        }
        return $builder;
    }

    private function createModelQuery(string $baseModelName)
    {
        if ($this->tableName == $baseModelName) {
            return DB::table($baseModelName);
        }

        $model = new $baseModelName;
        if (!($model instanceof Model)) {
            throw new NotModelException($baseModelName);
        }

        return $model::query();
    }

    private function getControllerFromRoute($route)
    {
        if (is_array($route)) {
            $maybeControllerWithMethod = current($route[1]);
            if ($maybeControllerWithMethod instanceof \Closure) {
                return null;
            }

            $splitedControllerMethod = explode('@', $maybeControllerWithMethod);
            $routingHandler = current($splitedControllerMethod);
            $maybeController = new $routingHandler;

            return new $maybeController instanceof Controller ? $maybeController : null;
        }

        return $route->controller;
    }
}
