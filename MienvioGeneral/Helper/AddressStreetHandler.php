<?php

namespace MienvioMagento\MienvioGeneral\Helper;

class AddressStreetHandler
{
    CONST MAX_LENGTH = 35;

    protected $mienvioStreet1;
    protected $mienvioStreet2;
    protected $mienvioReferences;

    protected $streetLine1;
    protected $streetLine2;
    protected $streetLine3;
    protected $neighborhood;
    protected $references;

    protected bool $internalNumberAlreadyAllocated = false;

    public function getMienvioStreet1()
    {
        return $this->mienvioStreet1;
    }

    public function getMienvioStreet2()
    {
        return $this->mienvioStreet2;
    }

    public function getMienvioReferences()
    {
        return $this->mienvioReferences;
    }
    /**
     * AddressStreetHandler constructor.
     *
     * @param string $streetLine1 Para calle
     * @param string $streetLine2 Para número exterior
     * @param string $streetLine3 Para número interior
     * @param string $neighborhood Para colonia
     * @param string $references Para referencias
     */
    public function __construct($streetLine1, $streetLine2, $streetLine3, $neighborhood = '', $references = '')
    {
        $this->streetLine1 = trim($streetLine1);
        $this->streetLine2 =  trim($streetLine2);
        $this->streetLine3 =  trim($streetLine3);
        $this->neighborhood =  trim($neighborhood);
        $this->references =  trim($references);

        $this->formatAddress();
    }


    protected function getInternalNumber(){
        $internalNumber = $this->streetLine3;

        if (!empty($internalNumber)) {
            // Si no contiene 'int' (sin importar mayúsculas/minúsculas) o si es numérico
            if (
                stripos($internalNumber, 'int') === false ||
                is_numeric($internalNumber)
            ) {
                $internalNumber = 'Int. ' . $internalNumber;
            }
        }
        return $internalNumber;
    }
    

    protected function internalNumberCanBeAllocated($mainString, $internalNumber, $mergeString = ' '){
        if (empty($internalNumber)) {
            return true;
        }

        $finalString = trim($mainString . $mergeString . $internalNumber);

        return strlen($finalString) <= 35;
    }

    protected function formatMienvioStreet1()
    {
        $street = trim($this->streetLine1 . ' ' . $this->streetLine2);
        if (strlen($street) > self::MAX_LENGTH) {
            $street = substr($street, 0, self::MAX_LENGTH);
        }

        $internalNumber = $this->getInternalNumber();

        $separator = ' ';
        if($this->internalNumberCanBeAllocated($street, $internalNumber, $separator)){
            $street = "{$street}{$separator}{$internalNumber}";
            $this->internalNumberAlreadyAllocated = true;
        } 

        return $street;
    }

    protected function formatMienvioStreet2()
    {
        $street = $this->neighborhood;
        if(!$this->internalNumberAlreadyAllocated){
            $separator = ', ';
            $internalNumber = $this->getInternalNumber();
            if($this->internalNumberCanBeAllocated($street, $internalNumber, $separator)){
                $street = "{$internalNumber}{$separator}{$street}";
                $this->internalNumberAlreadyAllocated = true;
            }
        }
        
        if (strlen($street) > self::MAX_LENGTH) {
            $street = substr($street, 0, self::MAX_LENGTH);
        }

        return $street;
    }


    protected function formatMienvioReferences()
    {
        $references = $this->references;

        if(!$this->internalNumberAlreadyAllocated){
            $separator = ', ';
            $internalNumber = $this->getInternalNumber();
            if($this->internalNumberCanBeAllocated($references, $internalNumber, $separator)){
                $references = "{$internalNumber}{$separator}{$references}";
                $this->internalNumberAlreadyAllocated = true;
            }
        }

        return $references;
    }

    protected function fiveFieldsFormat()
    {
        $this->mienvioStreet1 = $this->formatMienvioStreet1();
        $this->mienvioStreet2 = $this->formatMienvioStreet2();
        $this->mienvioReferences = $this->formatMienvioReferences();
    }

    protected function threeFieldsFormat()
    {
        $this->mienvioStreet1 = $this->streetLine1;
        $this->mienvioStreet2 = $this->streetLine2;
        $this->mienvioReferences = $this->streetLine3;
        if (strlen($this->mienvioStreet1) > self::MAX_LENGTH) {
            $this->mienvioStreet1 = substr($this->mienvioStreet1, 0, self::MAX_LENGTH);
        }
        if (strlen($this->mienvioStreet2) > self::MAX_LENGTH) {
            $this->mienvioStreet2 = substr($this->mienvioStreet2, 0, self::MAX_LENGTH);
        }
        if (strlen($this->mienvioReferences) > self::MAX_LENGTH) {
            $this->mienvioReferences = substr($this->mienvioReferences, 0, self::MAX_LENGTH);
        }
    }


    protected function numberOfFieldsReceived(){
        $numberOfFields = 0;
        if (!empty($this->streetLine1)) {
            $numberOfFields++;
        }
        if (!empty($this->streetLine2)) {
            $numberOfFields++;
        }
        if (!empty($this->streetLine3)) {
            $numberOfFields++;
        }
        if (!empty($this->neighborhood)) {
            $numberOfFields++;
        }
        if (!empty($this->references)) {
            $numberOfFields++;
        }

        return $numberOfFields;
    }

    protected function formatAddress()
    {
        if($this->numberOfFieldsReceived() === 5){
            $this->fiveFieldsFormat();
            return;
        }

        $this->threeFieldsFormat();
    }
}