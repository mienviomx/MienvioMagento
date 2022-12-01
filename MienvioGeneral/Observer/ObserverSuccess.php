<?php

namespace MienvioMagento\MienvioGeneral\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\ResourceModel\Quote\Address\Rate\CollectionFactory;
use Magento\Quote\Model\QuoteRepository;
use Psr\Log\LoggerInterface;
use MienvioMagento\MienvioGeneral\Helper\Data as Helper;

class ObserverSuccess implements ObserverInterface
{
    private $collectionFactory;
    private $quoteRepository;
    public const XML_PATH_Street_store = 'shipping/origin/street_line2';

    /**
     * Defines if quote endpoint will be used at rates
     * @var boolean
     */
    public const IS_QUOTE_ENDPOINT_ACTIVE = true;

    protected $_storeManager;

    public function __construct(
        CollectionFactory $collectionFactory,
        QuoteRepository $quoteRepository,
        \Magento\Framework\HTTP\Client\Curl $curl,
        Helper $helperData,
        LoggerInterface $logger,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->_storeManager = $storeManager;
        $this->collectionFactory = $collectionFactory;
        $this->quoteRepository = $quoteRepository;
        $this->_code = 'mienviocarrier';
        $this->_logger = $logger;
        $this->_mienvioHelper = $helperData;
        $this->_curl = $curl;
    }

    public function execute(Observer $observer)
    {
        $this->initLogger();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getData('order');
        $shippingMethodObject = $order->getShippingMethod(true);
        if (!$shippingMethodObject) {
            return $this;
        }
        $shippingId = $shippingMethodObject->getMethod();
        $chosenServiceLevel = '';
        $chosenProvider = '';

        // $dataOrder = $order->getData();
        // $order_quote_id = $dataOrder['quote_id'];
        // $order_shipping_amount = $dataOrder['shipping_amount'];
        // $order_shipping_description = $dataOrder['shipping_description'];
        // $order_shipping_method = $dataOrder['shipping_method'];
        $quoteId = $order->getQuoteId();
        $quote = $this->quoteRepository->get($quoteId);

        $shippingCost = $order->getShippingAmount();

        $isFreeActive = $this->checkIfIsFreeShipping();
        $freeLabel = $isFreeActive ? 'ACTIVE' : 'INACTIVE';
        // $titleMethodFree = $this->_mienvioHelper->getTitleMethodFree();

        $this->_logger->debug('ObserverSuccess@execute :: shipping cost is ' . $shippingCost . ' and shipping free in Mienvío plugin is ' . $freeLabel);

        if ($shippingCost == 0 && $isFreeActive === true) {
            $this->_logger->debug('ObserverSuccess@execute :: free shipping flow');
            try {
                $mienvioResponse = $this->saveFreeShipping($observer);
                $mienvioAmount = $mienvioResponse['rates'][0]["amount"];
                $mienvioProvider = $mienvioResponse['rates'][0]["provider"];
                $mienvioServiceLevel = $mienvioResponse['rates'][0]["servicelevel"];
                $mienvioQuoteId = $mienvioResponse['quote_id'];
                //$order->setShippingAmount($mienvioAmount);
                $order->setBaseShippingAmount($mienvioAmount);
                $order->setBaseShippingDiscountAmount($mienvioAmount);
                $order->setShippingDiscountAmount($mienvioAmount);
                $order->setShippingInclTax($mienvioAmount);
                $order->setBaseShippingInclTax($mienvioAmount);
                $order->setMienvioQuoteId($mienvioQuoteId);
                $order->setShippingDescription($mienvioProvider.' - '.$mienvioServiceLevel);
                $order->setShippingMethod('mienviocarrier_'.$mienvioServiceLevel.'-'.$mienvioProvider);
                $order->save();
            } catch (\Exception $e) {
                $order->setMienvioQuoteId('Generar guía Manual');
                $order->save();
                $this->_logger->debug('Exception in ObserverSuccess@execute :: free shipping flow: ' . $e->getMessage());
            }

            return $this;
        }

        $this->_logger->debug('ObserverSuccess@execute :: normal flow');
        $this->_logger->debug('ObserverSuccess@execute :: shipping method carrier code: ' . $shippingMethodObject->getCarrierCode());
        $this->_logger->debug('ObserverSuccess@execute :: self shipping method carrier code: ' . $this->_code);

        if ($shippingMethodObject->getCarrierCode() != $this->_code) {
            $this->_logger->debug('ObserverSuccess@execute :: diff in carrier code, returning');
            return $this;
        }

        if (self::IS_QUOTE_ENDPOINT_ACTIVE) {
            $shippingInfo = explode("-", $shippingId);
            $chosenServiceLevel = $shippingInfo[0];
            $chosenProvider = $shippingInfo[1];
        }

        $this->_logger->debug('ObserverSuccess@execute :: chosen provider and service: ' . $chosenProvider . " ({$chosenServiceLevel})");

        // Logic to save orders in mienvio api
        try {
            $baseUrl =  $this->_mienvioHelper->getEnvironment();
            $apiKey = $this->_mienvioHelper->getMienvioApi();
            $getPackagesUrl = $baseUrl . 'api/packages';
            $createAddressUrl = $baseUrl . 'api/addresses';
            $createShipmentUrl = $baseUrl . 'api/shipments';
            $createQuoteUrl     = $baseUrl . 'api/quotes';

            $order = $observer->getEvent()->getOrder();
            $order->setMienvioCarriers($shippingId);
            // $orderId = $order->getId();
            $orderData = $order->getData();
            $quoteId = $order->getQuoteId();

            if ($quoteId === null) {
                $this->_logger->debug('ObserverSuccess@execute :: quote id is null, returning');
                return $this;
            }

            $quote = $this->quoteRepository->get($quoteId);
            $shippingAddress = $quote->getShippingAddress();
            $countryId = $shippingAddress->getCountryId();
            $destRegion     = $shippingAddress->getRegion();
            $destRegionCode = $shippingAddress->getRegionCode();
            $storeName = trim($this->_mienvioHelper->getStoreName() ?? '');
            $storePhone = trim($this->_mienvioHelper->getStorePhone() ?? '');
            $storeEmail = trim($this->_mienvioHelper->getStoreEmail() ?? '');

            if ($shippingAddress === null) {
                $this->_logger->debug('ObserverSuccess@execute :: shipping address is null, returning');
                return $this;
            }

            $fromData = $this->createAddressDataStr(
                'from',
                empty($storeName) ? "MIENVIO DE MEXICO" : $storeName,
                $this->_mienvioHelper->getOriginStreet(),
                $this->_mienvioHelper->getOriginStreet2(),
                $this->_mienvioHelper->getOriginZipCode(),
                empty($storeEmail) ? "ventas@mienvio.mx" : $storeEmail,
                empty($storePhone) ? "5551814040" : $storePhone,
                '',
                $countryId,
                $this->_mienvioHelper->getOriginCity()
            );

            $customerName  = $shippingAddress->getName();
            $customermail  = $shippingAddress->getEmail();
            $customerPhone = $shippingAddress->getTelephone();
            $countryId     = $shippingAddress->getCountryId();

            $toStreet2 = empty($shippingAddress->getStreetLine(2)) ? $shippingAddress->getStreetLine(1) : $shippingAddress->getStreetLine(2);

            $toData = $this->createAddressDataStr(
                'to',
                $customerName,
                $shippingAddress->getStreetLine(1),
                $toStreet2,
                $shippingAddress->getPostcode(),
                $customermail,
                $customerPhone,
                $shippingAddress->getStreetLine(3),
                $countryId,
                $destRegion,
                $destRegionCode,
                $shippingAddress->getCity()
            );

            $options = [CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];
            $this->_curl->setOptions($options);

            $this->_logger->debug('ObserverSuccess@execute :: create address url: ' . $createAddressUrl);
            $this->_logger->debug('ObserverSuccess@execute :: create address FROM request: ' . json_encode($fromData));

            $this->_curl->post($createAddressUrl, json_encode($fromData));
            $addressFromResp = json_decode($this->_curl->getBody());

            try {
                $addressFromId = $addressFromResp->{'address'}->{'object_id'};
            } catch (\Exception $e) {
                $this->_logger->debug('Exception in ObserverSuccess@execute :: create address FROM response: ' . $this->_curl->getBody());
                return;
            }

            $this->_logger->debug('ObserverSuccess@execute :: create address TO request: ' . json_encode($toData));

            $this->_curl->post($createAddressUrl, json_encode($toData));
            $addressToResp = json_decode($this->_curl->getBody());

            try {
                $addressToId = $addressToResp->{'address'}->{'object_id'};
            } catch (\Exception $e) {
                $this->_logger->debug('Exception in ObserverSuccess@execute :: create address TO response: ' . $this->_curl->getBody());
                return;
            }

            /* Measures */
            $itemsMeasures = $this->getOrderDefaultMeasures($order->getAllVisibleItems());
            $packageWeight = $this->convertWeight($orderData['weight']);

            if (self::IS_QUOTE_ENDPOINT_ACTIVE) {
                $mienvioResponse = $this->createQuoteFromItems(
                    $itemsMeasures['items'],
                    $addressFromId,
                    $addressToId,
                    $createQuoteUrl,
                    $chosenServiceLevel,
                    $chosenProvider,
                    $order->getIncrementId()
                );
                $mienvioQuoteId = $mienvioResponse['quote_id'];
                $order->setMienvioQuoteId($mienvioQuoteId);
                $order->save();
                return $this;
            }

            $packageVolWeight = $itemsMeasures['vol_weight'];
            $orderLength = $itemsMeasures['length'];
            $orderWidth  = $itemsMeasures['width'];
            $orderHeight = $itemsMeasures['height'];
            $orderDescription = $itemsMeasures['description'];
            $numberOfPackages = 1;

            $packageVolWeight = ceil($packageVolWeight);
            $orderWeight = $packageVolWeight > $packageWeight ? $packageVolWeight : $packageWeight;
            $orderDescription = substr($orderDescription, 0, 30);

            try {
                $packages = $this->getAvailablePackages($getPackagesUrl, $options);
                $packageCalculus = $this->calculateNeededPackage($orderWeight, $packageVolWeight, $packages);
                $chosenPackage = $packageCalculus['package'];
                $numberOfPackages = $packageCalculus['qty'];

                $orderLength = $chosenPackage->{'length'};
                $orderWidth = $chosenPackage->{'width'};
                $orderHeight = $chosenPackage->{'height'};
            } catch (\Exception $e) {
                $this->_logger->debug('Exception in ObserverSuccess@execute :: getting available packages: ' . $e->getMessage());
            }

            $this->_logger->debug('ObserverSuccess@execute :: Order info: ' . json_encode([
                'packageWeight' => $packageWeight,
                'volWeight' => $packageVolWeight,
                'maxWeight' => $orderWeight,
                'package' => $chosenPackage,
                'description' => $orderDescription,
                'numberOfPackages' => $numberOfPackages
            ]));

            $shipmentReqData = [
                'object_purpose' => 'PURCHASE',
                'address_from' => $addressFromId,
                'address_to' => $addressToId,
                'weight' => $orderWeight,
                'declared_value' => $orderData['subtotal_incl_tax'],
                'description' => $orderDescription,
                'source_type' => 'api',
                'length' => $orderLength,
                'width' => $orderWidth,
                'height' => $orderHeight,
                'rate' => $shippingId,
                'quantity' => $numberOfPackages,
                'source_type' => 'magento',
                'order' => [
                    'marketplace' => 'magento',
                    'object_id' => $orderData['quote_id']
                ]
            ];

            $this->_logger->info('ObserverSuccess@execute :: Create shipment url: ' . $createShipmentUrl);
            $this->_logger->info('ObserverSuccess@execute :: Shipment request: ' . json_encode($shipmentReqData));

            $this->_curl->post($createShipmentUrl, json_encode($shipmentReqData));
            $response = json_decode($this->_curl->getBody());

            $this->_logger->info('ObserverSuccess@execute :: Create shipment response: ' . $this->_curl->getBody());
        } catch (\Exception $e) {
            $this->_logger->debug("Exception in ObserverSuccess@execute :: storing shipment: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Create quote using given items
     *
     * @param  array $items
     * @param  integer $addressFromId
     * @param  integer $addressToId
     * @param  string $createQuoteUrl
     * @param  string $servicelevel
     * @param  string $provider
     * @param  string $orderId
     * @return string
     */
    private function createQuoteFromItems($items, $addressFromId, $addressToId, $createQuoteUrl, $servicelevel, $provider, $orderId)
    {
        $this->_logger->debug('ObserverSuccess@createQuoteFromItems :: About to create purchase shipment');

        $quoteReqData = [
            'items'         => $items,
            'address_from'  => $addressFromId,
            'address_to'    => $addressToId,
            'servicelevel'  => $servicelevel,
            'provider'      => $provider,
            'object_purpose' => 'PURCHASE',
            'order_id'      => $orderId,
            'shop_url'     => $this->_storeManager->getStore()->getUrl(),
            'source_type' => 'magento'
        ];

        $this->_logger->debug('ObserverSuccess@createQuoteFromItems :: create quote url: ' . $createQuoteUrl);
        $this->_logger->debug('ObserverSuccess@createQuoteFromItems :: create quote request ' . json_encode($quoteReqData));

        $this->_curl->post($createQuoteUrl, json_encode($quoteReqData));
        $quoteResponse = json_decode(stripslashes($this->_curl->getBody()), true);

        $this->_logger->debug('ObserverSuccess@createQuoteFromItems :: create quote response ' . json_encode($quoteResponse));

        return $quoteResponse;
    }

    /**
     * Retrieves total measures of given items
     *
     * @param  Items $items
     * @return
     */
    private function getOrderDefaultMeasures($items)
    {
        $packageVolWeight = 0;
        $orderLength = 0;
        $orderWidth = 0;
        $orderHeight = 0;
        $orderDescription = '';
        $itemsArr = [];

        foreach ($items as $item) {
            if ($item->getParentItem()) {
                continue;
            }
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $productName = $item->getName();
            $productSku = $item->getSku();
            $orderDescription .= $productName . ' ';
            //$product = $objectManager->create('Magento\Catalog\Model\Product')->loadByAttribute('name', $productName);
            $productRepository = $objectManager->get('\Magento\Catalog\Model\ProductRepository');
            $product = $productRepository->get($productSku);
            $dimensions = $this->getDimensionItems($product);

            if (is_array($dimensions)) {
                $length = $dimensions['length'];
                $width  = $dimensions['width'];
                $height = $dimensions['height'];
                $weight = $dimensions['weight'];
            } else {
                $length = 2;
                $width  = 2;
                $height = 2;
                $weight = 1;
            }

            $orderLength += $length;
            $orderWidth  += $width;
            $orderHeight += $height;

            $volWeight = $this->calculateVolumetricWeight($length, $width, $height);
            $packageVolWeight += $volWeight;
            $itemsArr[] = [
                'id' => $productSku,
                'name' => $productName,
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'weight' => $weight,
                'volWeight' => $volWeight,
                'qty' => $item->getQtyordered(),
                'declared_value' => $item->getprice(),
            ];
        }

        return [
            'vol_weight'  => $packageVolWeight,
            'length'      => $orderLength,
            'width'       => $orderWidth,
            'height'      => $orderHeight,
            'description' => $orderDescription,
            'items'       => $itemsArr
        ];
    }


    private function getDimensionItems($product)
    {
        $length = 0;
        $width = 0;
        $height = 0;
        $weight = 0;

        if ($product->getData('ts_dimensions_length') != 0 && $product->getData('ts_dimensions_length') != null) {
            if ($this->_mienvioHelper->getMeasures() === 1) {
                $length = $product->getData('ts_dimensions_length');
                $width = $product->getData('ts_dimensions_width');
                $height = $product->getData('ts_dimensions_height');
                $weight = $product->getData('weight');
            } else {
                $length = $this->convertInchesToCms($product->getData('ts_dimensions_length'));
                $width = $this->convertInchesToCms($product->getData('ts_dimensions_width'));
                $height = $this->convertInchesToCms($product->getData('ts_dimensions_height'));
                $weight = $this->convertWeight($product->getData('weight'));
            }
        } elseif ($product->getAttribute('length') != 0 && $product->getAttribute('length') != null) {
            if ($this->_mienvioHelper->getMeasures() === 1) {
                $length = $product->getAttribute('length');
                $width = $product->getAttribute('width');
                $height = $product->getAttribute('height');
                $weight = $product->getAttribute('weight');
            } else {
                $length = $this->convertInchesToCms($product->getAttribute('length'));
                $width = $this->convertInchesToCms($product->getAttribute('width'));
                $height = $this->convertInchesToCms($product->getAttribute('height'));
                $weight = $this->convertWeight($product->getAttribute('weight'));
            }
        } elseif ($product->getData('shipping_lengthcarton') != 0 && $product->getData('shipping_lengthcarton') != null) {
            if ($this->_mienvioHelper->getMeasures() === 1) {
                $length = $product->getData('shipping_lengthcarton');
                $width = $product->getData('shipping_widthcarton');
                $height = $product->getData('shipping_heightcarton');
                $weight = $product->getData('shipping_weightcarton');
            } else {
                $length = $this->convertInchesToCms($product->getData('shipping_lengthcarton'));
                $width = $this->convertInchesToCms($product->getData('shipping_widthcarton'));
                $height = $this->convertInchesToCms($product->getData('shipping_heightcarton'));
                $weight = $this->convertWeight($product->getData('shipping_weightcarton'));
            }
        } elseif ($product->getData('length') != 0 && $product->getData('length') != null) {
            if ($this->_mienvioHelper->getMeasures() === 1) {
                $length = $product->getData('length');
                $width = $product->getData('width');
                $height = $product->getData('height');
                $weight = $product->getData('weight');
            } else {
                $length = $this->convertInchesToCms($product->getData('length'));
                $width = $this->convertInchesToCms($product->getData('width'));
                $height = $this->convertInchesToCms($product->getData('height'));
                $weight = $this->convertWeight($product->getData('weight'));
            }
        } else {
            $length = 0.5;
            $width = 0.5;
            $height = 0.5;
            $weight = 0.2;
            $this->_logger->debug('This item will be trated as a kit with measures in 0 ' . json_encode($product->getData()));
        }
        return array(
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'weight' => $weight
        );
    }

    /**
     * Retrieve user packages
     *
     * @param  string $baseUrl
     * @return array
     */
    private function getAvailablePackages($url, $options)
    {
        $this->_curl->setOptions($options);
        $this->_curl->get($url);
        $response = json_decode($this->_curl->getBody());
        $packages = $response->{'results'};

        $this->_logger->debug("ObserverSuccess@getAvailablePackages :: packages: " . json_encode($packages));

        return $packages;
    }

    /**
     * Calculates volumetric weight of given measures
     *
     * @param  float $length
     * @param  float $width
     * @param  float $height
     * @return float
     */
    private function calculateVolumetricWeight($length, $width, $height)
    {
        $volumetricWeight = round(((1 * $length * $width * $height) / 5000), 4);

        return $volumetricWeight;
    }

    /**
     * Retrieves weight in KG
     *
     * @param  float $_weigth
     * @return float
     */
    private function convertWeight($_weigth)
    {
        return ceil($_weigth * 0.45359237);

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

    /**
     * Convert inches to cms
     *
     * @param  float $inches
     * @return float
     */
    private function convertInchesToCms($inches)
    {
        return $inches * 2.54;
    }

    /**
     * Calculates needed package size for order items
     *
     * @param  float $orderWeight
     * @param  float $orderVolWeight
     * @param  array $packages
     * @return array
     */
    private function calculateNeededPackage($orderWeight, $orderVolWeight, $packages)
    {
        $chosenPackVolWeight = 10000;
        $chosenPackage = null;
        $biggerPackage = null;
        $biggerPackageVolWeight = 0;
        $qty = 1;

        foreach ($packages as $package) {
            $packageVolWeight = $this->calculateVolumetricWeight(
                $package->{'length'},
                $package->{'width'},
                $package->{'height'}
            );

            if ($packageVolWeight > $biggerPackageVolWeight) {
                $biggerPackageVolWeight = $packageVolWeight;
                $biggerPackage = $package;
            }

            if ($packageVolWeight < $chosenPackVolWeight && $packageVolWeight >= $orderVolWeight) {
                $chosenPackVolWeight = $packageVolWeight;
                $chosenPackage = $package;
            }
        }

        if (is_null($chosenPackage)) {
            // then use bigger package
            $chosenPackage = $biggerPackage;
            $sizeRatio = $orderVolWeight/$biggerPackageVolWeight;
            $qty = ceil($sizeRatio);
        }

        return [
            'package' => $chosenPackage,
            'qty' => $qty
        ];
    }

    /**
     * Creates an string with the address data
     *
     * @param  string $name
     * @param  string $street
     * @param  string $street2
     * @param  string $zipcode
     * @param  string $email
     * @param  string $phone
     * @param  string $reference
     * @param  string $countryCode
     * @return string
     */
    private function createAddressDataStr($type, $name, $street, $street2, $zipcode, $email, $phone, $reference, $countryCode, $destRegion = null, $destRegionCode = null, $destCity = null)
    {
        $data = [
            'object_type' => 'PURCHASE',
            'name' => $name,
            'street' => $street,
            'street2' => $street2,
            'email' => $email,
            'phone' => $phone,
            'reference' => $reference
        ];

        $location = $this->_mienvioHelper->getLocation();

        if ($location == 'street2') {
            if ($countryCode === 'MX') {
                $data['zipcode'] = $zipcode;
            } elseif ($countryCode === 'CO') {
                if ($type === 'from') {
                    $data['level_1'] = $street2;
                    $data['level_2'] = $this->getLevel2FromAddress($destRegion, $destRegionCode, $destCity, $countryCode);
                }
                if ($type === 'to') {
                    if ($destCity != '') {
                        $data['level_1'] = $destCity;
                        $data['level_2'] = $this->getLevel2FromAddress($destRegion, $destRegionCode, $destCity, $countryCode);
                    } elseif ($street2 != '') {
                        $data['level_1'] = $street2;
                        $data['level_2'] = $this->getLevel2FromAddress($destRegion, $destRegionCode, $destCity, $countryCode);
                    }
                }
            } else {
                $data['level_1'] = $street2;
                $data['level_2'] = $this->getLevel2FromAddress($destRegion, $destRegionCode, $destCity);
            }
        } elseif ($location == 'zipcode') {
            if ($countryCode === 'MX') {
                $data['zipcode'] = $zipcode;
            } else {
                $data['level_1'] = $zipcode;
                $data['level_2'] = $this->getLevel2FromAddress($destRegion, $destRegionCode, $destCity);
            }
        } else {
            if ($countryCode === 'MX') {
                $data['zipcode'] = $zipcode;
            } else {
                $data['level_1'] = $zipcode;
                $data['level_2'] = $this->getLevel2FromAddress($destRegion, $destRegionCode, $destCity);
            }
        }

        return $data;
    }

    /*
     * Valida que los campos de ciudad, region y código de región no sean vacios.
     * Se implementa esta función ya que magento dependiendo de la configuraciones de
     * dirección de origen y destino, cambia el campo donde se valida el nivel 2 de la direccion.
     *
     * Se añade la validación para revisar que el el nivel 2 se este tomando de acuerdo a la inversa desde region a ciudad.
     */
    private function getLevel2FromAddress($destRegion, $destRegionCode, $destCity, $country = null)
    {
        if ($country === 'CO') {
            $level2 = $destRegionCode;
            if ($level2 == null) {
                $level2 = $destRegion;
                if ($level2 == null) {
                    $level2 = $destCity;
                }
            }
        } else {
            $level2 = $destCity;
            if ($level2 == null) {
                $level2 = $destRegion;
                if ($level2 == null) {
                    $level2 = $destRegionCode;
                }
            }
        }

        return $level2;
    }

    private function saveFreeShipping($observer)
    {
        $order = $observer->getData('order');
        $shippingMethodObject = $order->getShippingMethod(true);
        $shippingId = $shippingMethodObject->getMethod();
        $chosenServiceLevel = $this->_mienvioHelper->getServiceLevel();
        $chosenProvider = $this->_mienvioHelper->getProvider();

        $this->_logger->info("ObserverSuccess@saveFreeShipping :: shipping id: {$shippingId}");
        $this->_logger->info("ObserverSuccess@saveFreeShipping :: order data [1]: " . json_encode($order->getData()));

        try {
            $baseUrl =  $this->_mienvioHelper->getEnvironment();
            $apiKey = $this->_mienvioHelper->getMienvioApi();
            $getPackagesUrl = $baseUrl . 'api/packages';
            $createAddressUrl = $baseUrl . 'api/addresses';
            $createShipmentUrl = $baseUrl . 'api/shipments';
            $createQuoteUrl     = $baseUrl . 'api/quotes';

            $order = $observer->getEvent()->getOrder();
            $order->setMienvioCarriers($shippingId);
            // $orderId = $order->getId();
            $orderData = $order->getData();
            $quoteId = $order->getQuoteId();

            $this->_logger->info("ObserverSuccess@saveFreeShipping :: order data [2]: " . json_encode($order->getData()));

            if ($quoteId === null) {
                $this->_logger->info("ObserverSuccess@saveFreeShipping :: quote id is null, returning");
                return $this;
            }

            $quote = $this->quoteRepository->get($quoteId);
            $shippingAddress = $quote->getShippingAddress();
            $countryId = $shippingAddress->getCountryId();

            if ($shippingAddress === null) {
                $this->_logger->info("ObserverSuccess@saveFreeShipping :: shipping address is null, returning");
                return $this;
            }

            $storeName = trim($this->_mienvioHelper->getStoreName() ?? '');
            $storePhone = trim($this->_mienvioHelper->getStorePhone() ?? '');
            $storeEmail = trim($this->_mienvioHelper->getStoreEmail() ?? '');

            $fromData = $this->createAddressDataStr(
                'from',
                empty($storeName) ? "MIENVIO DE MEXICO" : $storeName,
                $this->_mienvioHelper->getOriginStreet(),
                $this->_mienvioHelper->getOriginStreet2(),
                $this->_mienvioHelper->getOriginZipCode(),
                empty($storeEmail) ? "ventas@mienvio.mx" : $storeEmail,
                empty($storePhone) ? "5551814040" : $storePhone,
                '',
                $countryId,
                '',
                '',
                $this->_mienvioHelper->getOriginCity()
            );

            $customerName  = $shippingAddress->getName();
            $customermail  = $shippingAddress->getEmail();
            $customerPhone = $shippingAddress->getTelephone();
            $countryId     = $shippingAddress->getCountryId();

            $toStreet2 = empty($shippingAddress->getStreetLine(2)) ? $shippingAddress->getStreetLine(1) : $shippingAddress->getStreetLine(2);

            $toData = $this->createAddressDataStr(
                'to',
                $customerName,
                $shippingAddress->getStreetLine(1),
                $toStreet2,
                $shippingAddress->getPostcode(),
                $customermail,
                $customerPhone,
                $shippingAddress->getStreetLine(3),
                $countryId,
                '',
                '',
                $shippingAddress->getCity()
            );

            $options = [CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];
            $this->_curl->setOptions($options);

            $this->_logger->debug('ObserverSuccess@saveFreeShipping :: create address url: ' . $createAddressUrl);
            $this->_logger->debug('ObserverSuccess@saveFreeShipping :: create address FROM request: ' . json_encode($fromData));

            $this->_curl->post($createAddressUrl, json_encode($fromData));
            $addressFromResp = json_decode($this->_curl->getBody());

            try {
                $addressFromId = $addressFromResp->{'address'}->{'object_id'};
            } catch (\Exception $e) {
                $this->_logger->debug('Exception in ObserverSuccess@saveFreeShipping :: create address FROM response: ' . $this->_curl->getBody());
                return;
            }

            $this->_curl->post($createAddressUrl, json_encode($toData));
            $addressToResp = json_decode($this->_curl->getBody());

            try {
                $addressToId = $addressToResp->{'address'}->{'object_id'};
            } catch (\Exception $e) {
                $this->_logger->debug('Exception in ObserverSuccess@saveFreeShipping :: create address TO response: ' . $this->_curl->getBody());
                return;
            }

            /* Measures */
            $itemsMeasures = $this->getOrderDefaultMeasures($order->getAllVisibleItems());
            $packageWeight = $this->convertWeight($orderData['weight']);

            if (self::IS_QUOTE_ENDPOINT_ACTIVE) {
                $response = $this->createQuoteFromItems(
                    $itemsMeasures['items'],
                    $addressFromId,
                    $addressToId,
                    $createQuoteUrl,
                    $chosenServiceLevel,
                    $chosenProvider,
                    $order->getIncrementId()
                );

                return $response;
            }
        } catch (\Exception $e) {
            $this->_logger->debug("Exception in ObserverSuccess@saveFreeShipping :: {$e->getMessage()}");
        }
    }

    private function checkIfIsFreeShipping()
    {
        $isActive = $this->_mienvioHelper->isFreeShipping();
        if (!$isActive) {
            return false;
        } else {
            return true;
        }
    }


    private function parseServiceLevel($serviceLevel)
    {
        $parsed = '';
        switch ($serviceLevel) {
            case 'estandar':
                $parsed = 'Estándar';
                break;
            case 'express':
                $parsed = 'Express';
                break;
            case 'saver':
                $parsed = 'Saver';
                break;
            case 'express_plus':
                $parsed = 'Express Plus';
                break;
            case 'economy':
                $parsed = 'Economy';
                break;
            case 'priority':
                $parsed = 'Priority';
                break;
            case 'worlwide_usa':
                $parsed = 'World Wide USA';
                break;
            case 'worldwide_usa':
                $parsed = 'World Wide USA';
                break;
            case 'regular':
                $parsed = 'Regular';
                break;
            case 'regular_mx':
                $parsed = 'Regular MX';
                break;
            case 'BE_priority':
                $parsed = 'Priority';
                break;
            case 'flex':
                $parsed = 'Flex';
                break;
            case 'scheduled':
                $parsed = 'Programado';
                break;
            default:
                $parsed = $serviceLevel;
        }

        return $parsed;
    }


    private function parseReverseServiceLevel($serviceLevel)
    {
        $parsed = '';
        switch ($serviceLevel) {
            case 'Estándar' :
                $parsed = 'estandar';
                break;
            case 'Express' :
                $parsed = 'express';
                break;
            case 'Saver' :
                $parsed = 'saver';
                break;
            case 'Express Plus' :
                $parsed = 'express_plus';
                break;
            case 'Economy' :
                $parsed = 'economy';
                break;
            case 'Priority' :
                $parsed = 'priority';
                break;
            case 'World Wide USA' :
                $parsed = 'worlwide_usa';
                break;
            case 'World Wide USA' :
                $parsed = 'worldwide_usa';
                break;
            case 'Regular' :
                $parsed = 'regular';
                break;
            case 'Regular MX' :
                $parsed = 'regular_mx';
                break;
            case 'Priority' :
                $parsed = 'BE_priority';
                break;
            case 'Flex' :
                $parsed = 'flex';
                break;
            case 'Programado' :
                $parsed = 'scheduled';
                break;
            default:
                $parsed = $serviceLevel;
        }

        return $parsed;
    }

    private function initLogger()
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/mienvioRates.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $this->_logger = $logger;
    }
}
