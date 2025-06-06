<?php

namespace MienvioMagento\MienvioGeneral\Plugin\Checkout;

class ShippingInformationManagementPlugin
{
    public function beforeSaveAddressInformation(
        \Magento\Checkout\Model\ShippingInformationManagement $subject,
        $cartId,
        \Magento\Checkout\Api\Data\ShippingInformationInterface $addressInformation
    ) {
        $shippingAddress = $addressInformation->getShippingAddress();
        $extensionAttributes = $shippingAddress->getExtensionAttributes();
        $data = $shippingAddress->getData();
        $writer = new \Laminas\Log\Writer\Stream(BP . '/var/log/shipping_address_data.log');
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter($writer);
        $logger->info('Shipping Address Data: ' . json_encode($data));

        // Soporte para extensionAttributes (camelCase) como array
        if (isset($data['extensionAttributes']) && is_array($data['extensionAttributes'])) {
            $extAttrs = $data['extensionAttributes'];
            if (isset($extAttrs['neighborhood'])) {
                $shippingAddress->setData('neighborhood', $extAttrs['neighborhood']);
            }
            if (isset($extAttrs['references'])) {
                $shippingAddress->setData('references', $extAttrs['references']);
            }
        }

        // Soporte para extension_attributes (snake_case) como array
        if (isset($data['extension_attributes']) && is_array($data['extension_attributes'])) {
            $extAttrs = $data['extension_attributes'];
            if (isset($extAttrs['neighborhood'])) {
                $shippingAddress->setData('neighborhood', $extAttrs['neighborhood']);
            }
            if (isset($extAttrs['references'])) {
                $shippingAddress->setData('references', $extAttrs['references']);
            }
        }

        // Soporte para extension attributes como objeto (estándar Magento)
        if ($extensionAttributes) {
            if (method_exists($extensionAttributes, 'getNeighborhood')) {
                $logger->info('Setting neighborhood from extension attributes', ['neighborhood' => $extensionAttributes->getNeighborhood()]);
                $shippingAddress->setData('neighborhood', $extensionAttributes->getNeighborhood());
            }
            if (method_exists($extensionAttributes, 'getReferences')) {
                $shippingAddress->setData('references', $extensionAttributes->getReferences());
            }
        }
    }
}