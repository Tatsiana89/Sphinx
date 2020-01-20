<?php

namespace Sphinx\SphinxSearch;

use http\Env\Request;
use phpDocumentor\Reflection\Types\String_;
use \Sphinx\SphinxClient;
use DB;
use \Illuminate\Pagination\Paginator;
use \Illuminate\Pagination\LengthAwarePaginator;

class SphinxInstance extends SphinxClient
{
    protected $indexes;

    public function __construct()
    {
        parent::__construct();

        // per-client-object settings
        $this->host = config('sphinxsearch.host') ?: 'localhost';
        $this->port = config('sphinxsearch.port') ?: 9312;
        // per-query settings
        $this->offset = 0;
        $this->limit = 25;
        $this->mode = self::SPH_MATCH_EXTENDED;

        $this->indexes = config('sphinxsearch.indexes') ?: [];
    }

    public function get($query, $index = '*', $comment = '', $trashed = false, $columns = ['*'],&$totalCount=0)
    {
        $r = $this->query($query, $index, $comment);
        $totalCount = (int)$r['total_found'];
        $matches = $r['matches'] ?? [];
        if (!$totalCount) {
            return false;
        }

        $modelsQuery = $this->getModelsQuery($matches, $index);

        if ($trashed) {
            $modelsQuery->withTrashed();
        }

        return $modelsQuery->get($columns);
    }

    public function getPaginated($query, $index = '*', $comment = '', $pageNum = 1, $perPage = 25)
    {
        Paginator::currentPageResolver(function () use (&$pageNum) {
            return (int)$pageNum;
        });
        $page = Paginator::resolveCurrentPage();
        $offset = ($page - 1) * $perPage;
        $offsetOld = $this->offset;
        $limitOld = $this->limit;
        $this->setLimits($offset, $perPage);

        $r = $this->query($query, $index, $comment);
        $totalCount = (int)$r['total_found'];
        $matches = $r['matches'] ?? [];
        if (!$totalCount) {
            return false;
        }

        $modelsQuery = $this->getModelsQuery($matches, $index);
        $result = $modelsQuery->get();

        $paginator = new LengthAwarePaginator($result, $totalCount, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);

        $this->setLimits($offsetOld, $limitOld);
        return $paginator;
    }

    private function getModelsQuery(array $matches, string $index)
    {
        $ids = array_keys($matches);
        $idsStr = implode(',', $ids);
        $indexConfig = $this->indexes[explode(' ', $index)[0]];
        $query = call_user_func([$indexConfig['modelname'], 'whereIn'], $indexConfig['column'], $ids);
        if (!empty($matches)) {
            $query->orderByRaw(DB::raw("FIELD(id, $idsStr)"));
        }

        return $query;
    }
    public function characterReplacement(string $str):string
    {
        return str_replace(config('sphinxsearch.character_to_replace') , '*' , $str);
    }
}
