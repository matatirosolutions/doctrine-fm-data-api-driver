<?php
/**
 * Created by PhpStorm.
 * User: stevewinter
 * Date: 23/05/2018
 * Time: 11:54
 */

private function generateWhere($params, $layout)
{
    $cols = $this->selectColumns($this->query);
    $cmd = $this->fmp->newCompoundFindCommand($layout);
    $find = false;

    $preferenceCounter = 1;
    $queryCounter = 1;

    for($c = 0; $c<count($this->query['WHERE']); $c++) {
        $query = $this->query['WHERE'][$c];

        if(array_key_exists($query['base_expr'], $cols)) {
            $field = $query['no_quotes']['parts'][1];
            $comp = $this->query['WHERE'][$c+1]['base_expr'];

            if('IN' == $comp) {
                $in = explode('|~|', $params[$preferenceCounter]);
                foreach($in as $key => $value) {
                    $req = $this->fmp->newFindRequest($layout);
                    $req->addFindCriterion($field, $value);
                    $cmd->add($queryCounter, $req);
                    $queryCounter++;
                }
            } else {
                if(!$find) {
                    $find = $this->fmp->newFindRequest($layout);
                    $cmd->add($queryCounter, $find);
                    $queryCounter++;
                }

                $op = '=' == $comp ? '==' : ('LIKE' == $comp ? '' : $comp);
                $value = $op.$params[$preferenceCounter];

                $find->addFindCriterion($field, $value);
            }
            $preferenceCounter++;
        }
    }

    return $cmd;
}