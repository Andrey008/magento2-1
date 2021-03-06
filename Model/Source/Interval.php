<?php
/**
 * Class PaymentMethodsAction
 *
 * @author      MundiPagg Embeddables Team <embeddables@mundipagg.com>
 * @copyright   2017 MundiPagg (http://www.mundipagg.com)
 * @license     http://www.mundipagg.com Copyright
 *
 * @link        http://www.mundipagg.com
 */

namespace MundiPagg\MundiPagg\Model\Source;

use Magento\Framework\Option\ArrayInterface;
use MundiPagg\MundiPagg\Model\Source\EavInterval;

class Interval implements ArrayInterface
{

    public function toOptionArray() {
        $eav = new EavInterval();
        return $eav->getAllOptions();
    }

}
