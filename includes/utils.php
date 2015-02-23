<?php
/**
 * Created by PhpStorm.
 * User: PC
 * Date: 15/02/2015
 * Time: 22:57
 */

/**
 * Get the refunded amount for a line item
 *
 * @param  float $beans to format
 * @return string
 */
function print_beans($beans) {
    $beans = floatval($beans);
    $beans = floor($beans) == round($beans, BEANS_DECIMALS) ?
        number_format($beans) : number_format($beans, BEANS_DECIMALS);
    $beans_name = defined ('BEANS_NAME') ? BEANS_NAME: 'beans';
    return "<span class='beans-unit'> $beans $beans_name</span>";
}

function beans_info_tag($card_name, $beans_rate){
    $info_html = sprintf(
                    __('%1$s // %2$s', 'woocommerce-beans'),
                    print_beans($beans_rate),
                    wc_price(1)
                );
    return " <a class='beans-info' target='_blank'  onclick='Beans.show(); return false;' ".
           " href='//www.beans.cards/\$$card_name;/'> ".
           "     $info_html <span class='beans-info-tag' >i</span> ".
           " </a> ";
}