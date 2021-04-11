<?php
/**
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\StoreDelivery
 * @author    Romain Ruaud <romain.ruaud@smile.fr>
 * @copyright 2017 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace Smile\StoreDelivery\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Cart;
include_once ('/home/vbrook/www/magento2/app/code/Oldsam/Functions/vb_functions.php');

/**
 * Store Delivery Carrier model
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 *
 * @category Smile
 * @package  Smile\StoreDelivery
 * @author   Romain Ruaud <romain.ruaud@smile.fr>
 */
class Carrier extends AbstractCarrier implements CarrierInterface
{
    /**
     * Constant for method code
     */
    const METHOD_CODE = 'smilestoredelivery';

    /**
     * @var string
     */
    protected $_code = self::METHOD_CODE;

    /**
     * Whether this carrier has fixed rates calculation
     *
     * @var boolean
     */
    protected $_isFixed = true;

    /**
     * @var ResultFactory
     */
    protected $rateResultFactory;

    /**
     * @var MethodFactory
     */
    protected $rateMethodFactory;

    /**
     * @var \Magento\Checkout\Model\Cart
     */
    private $cart;
    
    /**
     * Carrier constructor
     *
     * @param ScopeConfigInterface $scopeConfig       Scope Configuration
     * @param ErrorFactory         $rateErrorFactory  Rate error Factory
     * @param LoggerInterface      $logger            Logger
     * @param ResultFactory        $rateResultFactory Rate result Factory
     * @param MethodFactory        $rateMethodFactory Rate method Factory
     * @param array                $data              Carrier Data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        Cart $cart,
        array $data = []
    ) {
        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->cart = $cart;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    /**
     * {@inheritdoc}
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }
        global $M2Servername;
        global $M2SUsername;
        global $M2Password;
        global $OSCDbname;
        $OSCconn = new \mysqli($M2Servername, $M2SUsername, $M2Password, $OSCDbname);
        $OSCconn->set_charset("utf8");
        if ($OSCconn->connect_error) {
            die("Connection failed: " . $OSCconn->connect_error);
        }
        $this->_logger->debug('starting store delivery calculation...');
        
        $shippingAddress = $this->cart->getQuote()->getShippingAddress();
        $city = $shippingAddress->getData('city');
        $this->_logger->debug('city = '.$city);
        $postCode = $shippingAddress->getData('postcode');
        $this->_logger->debug('postcode = '.$postCode);
        $street = $shippingAddress->getData('street');
        $this->_logger->debug('street = '.$street);
        $company = $shippingAddress->getData('company');
        $this->_logger->debug('company = '.$company);
        if ((strpos($company, 'Boxberry') !== FALSE)) {
            $pvz_carrier_id = 3;
        } elseif ((strpos($company, 'СДЭК') !== FALSE)) {
            $pvz_carrier_id = 8;
        } else {
            $pvz_carrier_id = -1;
        }
        if ($pvz_carrier_id == -1) {
            return false;
        }
        $region_id = $shippingAddress->getData('region_id');
        

        $subTotal = $this->cart->getQuote()->getSubtotal();
        $items = $this->cart->getQuote()->getAllItems();
        $weight = 0;
        foreach($items as $item) {
            $weight += ($item->getWeight() * $item->getQty()) ;        
        }

        $pvz_array = vb_pvz_array_by_parameters($OSCconn, $postCode, $pvz_carrier_id);
        if ($pvz_array == -1) {
            return false;
        }
        $shippingCost = vb_getOrderShippingCost($OSCconn, $weight, $subTotal, $pvz_carrier_id, $pvz_array['tier'], $pvz_array['vb_pvz_id']); 

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create();

        $method->setCarrier($this->getCarrierCode());
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->getCarrierCode());
        $method->setMethodTitle($this->getConfigData('name'));
        $method->setMethodTitle('ПВЗ '.$company);

        $amount = $this->getConfigData('price');

        $price = $this->getFinalPriceWithHandlingFee($amount);

        $method->setPrice($shippingCost);
        $method->setCost($shippingCost);
 
        $result->append($method);
        mysqli_close($OSCconn);

        return $result;
    }
}
