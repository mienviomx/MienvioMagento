<?php
namespace MienvioMagento\MienvioGeneral\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;
use MienvioMagento\MienvioGeneral\Helper\Data as Helper;



class Mienviorates extends AbstractCarrier implements CarrierInterface
{

    private $directoryHelper;
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        Helper $helperData,
        \Magento\Directory\Helper\Data $directoryHelper,
        array $data = []
    ) {
        $this->_code = 'mienviocarrier';
        $this->lbs_kg = 0.45359237;
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_logger = $logger;
        $this->_curl = $curl;
        $this->_mienvioHelper = $helperData;
        $this->directoryHelper = $directoryHelper;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    public function collectRates(RateRequest $request)
    {
        $isActive = $this->_mienvioHelper->isMienvioActive();

        if(!$isActive){
            return false;
        }

        $result = $this->_rateResultFactory->create();

        $apiKey = $this->_mienvioHelper->getMienvioApi();
        $apiSource = $this->getConfigData('apikey');

        if ($apiKey == "" || $apiSource == "NA") {
            return false;
        }

        try {
            $this->_logger->debug("obj", ["obj" => $request]);
            $destCountryId = $request->getDestCountryId();
            $destCountry = $request->getDestCountry();
            $destRegion = $request->getDestRegionId();
            $destRegionCode = $request->getDestRegionCode();
            $destFullStreet = $request->getDestStreet();
            $destStreet = "";
            $destSuburb = "";
            $destCity = $request->getDestCity();
            $destPostcode = $request->getDestPostcode();
            if ($destFullStreet != null && $destFullStreet != "") {
                $destFullStreetArray = explode("\n", $destFullStreet);
                $count = count($destFullStreetArray);
                if ($count > 0 && $destFullStreetArray[0] !== false) {
                    $destStreet = $destFullStreetArray[0];
                }
                if ($count > 1 && $destFullStreetArray[1] !== false) {
                    $destSuburb = $destFullStreetArray[1];
                }
            }
            $packageValue = $request->getPackageValue();
            $packageWeight = $request->getPackageWeight();
            $fromZipCode = $request->getPostcode();
            $realWeight = $this->convertWeight($packageWeight);
            // TODO: Change api url to production
            // Call Api to create rutes
            $url = 'http://localhost:8000/api/shipments';
            $post_data = '{
                     "object_purpose": "QUOTE",
                     "zipcode_from": '.$fromZipCode.',
                     "zipcode_to": '.$destPostcode.',
                     "weight": '.$realWeight.',
                     "source_type": "web_portal",
                     "length": 10,
                     "width": 10,
                     "height": 10
                    }';
            $this->_logger->debug("postdata", ["postdata" => $post_data]);


            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];
            $this->_curl->setOptions($options);
            $this->_curl->post($url, $post_data);
            $response = $this->_curl->getBody();
            $json_obj = json_decode($response);
            $obj_id = $json_obj->{'shipment'}->{'object_id'};
            $this->_curl->get($url . '/'.$obj_id. '/rates?limit=1000000');
            $responseRates = $this->_curl->getBody();
            $json_obj_rates = json_decode($responseRates);
            $totalCount = $json_obj_rates->{'total_count'};
            $this->_logger->debug("rates", ["rates" => $json_obj_rates]);
            if($totalCount > 0 ){
                $rates_obj =  $json_obj_rates->{'results'};
                foreach ($rates_obj as $rate) {
                    if (is_object($rate)) {
                        // Add shipping option with shipping price
                        $method = $this->_rateMethodFactory->create();
                        $method->setCarrier($this->getCarrierCode());
                        $method->setCarrierTitle($rate->{'provider'});
                        $method->setMethod($rate->{'object_id'});
                        $method->setMethodTitle($rate->{'servicelevel'});
                        $method->setPrice($rate->{'amount'});
                        $method->setCost($rate->{'amount'});
                        $result->append($method);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug("Rates Exception");
            $this->_logger->debug($e);
        }
        return $result;
    }

    private function convertWeight($_weigth){
        $storeWeightUnit = $this->directoryHelper->getWeightUnit();
        $weight = 0;
        switch ($storeWeightUnit) {
            case 'lbs':
                $weight = $_weigth * $this->lbs_kg;
                break;
            case 'kgs':
                $weight = $_weigth;
                break;
        }
        return ceil($weight);
    }

}