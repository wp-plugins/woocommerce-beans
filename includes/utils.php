<?php
/**
 * Created by PhpStorm.
 * User: PC
 * Date: 15/02/2015
 * Time: 22:57
 */


function smart_beans_format($n, $n_decimals)
{   $n = floatval($n);
    return ((floor($n) == round($n, $n_decimals)) ? number_format($n) : number_format($n, $n_decimals));
}