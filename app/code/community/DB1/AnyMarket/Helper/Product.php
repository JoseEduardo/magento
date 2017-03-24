<?php

class DB1_AnyMarket_Helper_Product extends DB1_AnyMarket_Helper_Data
{
    /**
     * @param $arrAttr
     * @param $key
     * @param $value
     * @return bool
     */
    private function checkArrayAttributes($arrAttr, $key, $value){
        foreach ($arrAttr as $arrVal) {
            if( isset($arrVal[$key]) ){
                if( $arrVal[$key] == $value ){
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param $UnitMeasurement
     * @param $value
     * @param $typeConvert
     * @return float
     */
    private function convertUnitMeasurement($UnitMeasurement, $value, $typeConvert){
        /*
        * $typeConvert = 0 receive from anymarket
        * $typeConvert = 1 send to anymarket

        * 'Centímetro', 'value' => '0'
        * 'Metro', 'value' => '1'
        * 'Decímetro', 'value' => '2'
        * 'Milímetro', 'value' => '3'
        */

        $valueRet = $value;
        if( $typeConvert == 0 ){
            switch ($UnitMeasurement) {
                case 0:
                    $valueRet = $value*1;
                    break;
                case 1:
                    $valueRet = $value/100;
                    break;
                case 2:
                    $valueRet = $value/10;
                    break;
                case 3:
                    $valueRet = $value*10;
                    break;
            }
        }else{
            switch ($UnitMeasurement) {
                case 0:
                    $valueRet = $value*1;
                    break;
                case 1:
                    $valueRet = $value*100;
                    break;
                case 2:
                    $valueRet = $value*10;
                    break;
                case 3:
                    $valueRet = $value/10;
                    break;
            }
        }

        return $valueRet;
    }

    /**
     * get all fields configured in configuration for descriptions
     *
     * @return string
     */
    public function getFieldsDescriptionConfig($storeID){
        $ConfigDescProd = Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_desc_field', $storeID);
        $fieldDesc = array();
        if ($ConfigDescProd && $ConfigDescProd != 'a:0:{}') {
            $ConfigDescProd = unserialize($ConfigDescProd);
            if (is_array($ConfigDescProd)) {
                foreach($ConfigDescProd as $ConfigDescProdRow) {

                    $attributeId = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product','description');
                    $attributesData = Mage::getResourceModel('catalog/product_attribute_collection')
                        ->addVisibleFilter()
                        ->addFieldToSelect(array('frontend_input', 'backend_type'))
                        ->addFieldToFilter('main_table.attribute_id', array('eq' => $attributeId));

                    $arrValuesAttrs = array_values( $attributesData->getData() );
                    $StoreIDAmProd = array_shift( $arrValuesAttrs );
                    if( ($StoreIDAmProd['frontend_input'] == "textarea") && ($StoreIDAmProd['backend_type'] == "text") ){
                        array_push($fieldDesc, $ConfigDescProdRow['descProduct']);
                    }
                }
            }
        }

        if( empty($fieldDesc) ) {
            array_push($fieldDesc, "description");
        }

        return $fieldDesc;
    }

    private function procGetIdAnymarketBySku($prodProf){
        if ($prodProf['error'] != '1') {
            $prodProf = json_decode(json_encode($prodProf['return']), true);
            if (isset($prodProf['content'])) {
                $firstItem = reset($prodProf['content']);
                return $firstItem['id'];
            }
        }
        return null;
    }

    public function getIdInAnymarketBySku($storeID, $product) {
        $HOST = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $headers = array(
            "Content-type: application/json",
            "Cache-Control: no-cache",
            "gumgaToken: " . $TOKEN
        );

        if($product->getTypeID() == "configurable") {
            $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $product);
            foreach ($childProducts as $child) {
                $prodProf = $this->CallAPICurl("GET", $HOST . "/v2/products?sku=" . urlencode($child->getSku()), $headers, null);
                $returnMet = $this->procGetIdAnymarketBySku($prodProf);
                if ($returnMet != null) {
                    return $returnMet;
                }
            }
        }elseif($product->getTypeID() == "bundle"){
            $selectionCollection = $product->getTypeInstance(true)->getSelectionsCollection(
                $product->getTypeInstance(true)->getOptionsIds($product), $product
            );

            foreach($selectionCollection as $option) {
                $prodOfBundle = Mage::getModel('catalog/product')->load( $option->getId() );
                $prodProf = $this->CallAPICurl("GET", $HOST . "/v2/products?sku=" . urlencode($prodOfBundle->getSku()), $headers, null);
                $returnMet = $this->procGetIdAnymarketBySku($prodProf);
                if ($returnMet != null) {
                    return $returnMet;
                }
            }
        }else{
            $sku = urlencode($product->getSku());
            $prodProf = $this->CallAPICurl("GET", $HOST . "/v2/products?sku=" . $sku, $headers, null);
            return $this->procGetIdAnymarketBySku($prodProf);
        }
        return null;
    }

    public function saveCallbackReceiver($sku){
        $cache = Mage::app()->getCache();
        $cache->save("sendToAnymarket", "callback_product_executed_".$sku, array($sku."_cached"), 60);
    }

    /**
     * Validate if callback send by module
     *
     * @param $storeID
     * @param $transmissionID
     * @return boolean
     */
    public function validateCallbackReceiver($storeID, $transmissionID){
        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $headers = array(
            "Content-type: application/json",
            "Cache-Control: no-cache",
            "gumgaToken: ".$TOKEN
        );

        $transmissionReturn = $this->CallAPICurl("GET", $HOST."/v2/transmissions/".$transmissionID, $headers, null);
        if($transmissionReturn['error'] != '1') {
            $JSONTransmission = $transmissionReturn['return'];
            $sku = $JSONTransmission->sku->partnerId;

            $cache = Mage::app()->getCache();
            if ( $cache->load( 'callback_product_executed_'.$sku ) ) {
                $cache->remove( 'callback_product_executed_'.$sku );
                return false;
            }

        }

        return true;
    }

    /**
     * get decription by configuration
     *
     * @param $product
     * @return string
     */
    public function getFullDescription($storeID, $product){
        $ConfigDescProd = Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_desc_field', $storeID);
        $descComplete = "";
        if ($ConfigDescProd && $ConfigDescProd != 'a:0:{}') {
            $ConfigDescProd = unserialize($ConfigDescProd);
            if (is_array($ConfigDescProd)) {
                foreach($ConfigDescProd as $ConfigDescProdRow) {
                    $descComplete .= $product->getData( $ConfigDescProdRow['descProduct'] ).' ';
                }
            }
        }else{
            $descComplete = $product->getDescription();
        }
        $baseURLMedia = Mage::getBaseUrl('media');

        $descComplete = str_replace('{{media url="', $baseURLMedia, $descComplete);
        $descComplete = str_replace('"}}', '', $descComplete);

        return trim($descComplete);
    }

    /**
     * create logs of product
     *
     * @param $returnProd array
     * @param $product Mage_Catalog_Model_Product
     */
    public function saveLogsProds($storeID, $priority, $returnProd, $product){
        $anymarketproductsUpdt = Mage::getModel('db1_anymarket/anymarketproducts')->setStoreId($storeID)->load($product->getId(), 'nmp_id');

        if(is_array($anymarketproductsUpdt->getData('store_id'))){
            $arrValuesProds = array_values($anymarketproductsUpdt->getData('store_id'));
            $StoreIDAmProd = array_shift($arrValuesProds);
        }else{
            $StoreIDAmProd = $anymarketproductsUpdt->getData('store_id');
        }

        if($returnProd['error'] == '1'){ //RETORNOU ERRO
            if( ($anymarketproductsUpdt->getData('nmp_sku') == null) || ($StoreIDAmProd != $storeID) ){
                $anymarketproducts = Mage::getModel('db1_anymarket/anymarketproducts');
                $anymarketproducts->setNmpId( $product->getId() );
                $anymarketproducts->setNmpSku( $product->getSku() );
                $anymarketproducts->setNmpName( $product->getName() );
                $anymarketproducts->setNmpDescError( $returnProd['return'] );
                $anymarketproducts->setNmpStatusInt("Erro");
                $anymarketproducts->setStatus("1");
                $anymarketproducts->setStores(array($storeID));
                $anymarketproducts->save();
            }else{
                $anymarketproductsUpdt->setNmpId( $product->getId() );
                $anymarketproductsUpdt->setNmpSku( $product->getSku() );
                $anymarketproductsUpdt->setNmpName( $product->getName() );
                $anymarketproductsUpdt->setNmpDescError( $returnProd['return'] );
                $anymarketproductsUpdt->setNmpStatusInt("Erro");
                $anymarketproductsUpdt->setStatus("1");
                $anymarketproductsUpdt->setStores(array($storeID));
                $anymarketproductsUpdt->save();
            }

            $URL = Mage::helper('adminhtml')->getUrl('adminhtml/catalog_product/edit', array('id' => $product->getId() ));
            $this->addMessageInBox($storeID, Mage::helper('db1_anymarket')->__('Error synchronizing AnyMarket products.'), Mage::helper('db1_anymarket')->__('Error on Sync product SKU: ').$product->getSku().', '.$returnProd['return'], $URL);
            $returnMet = $returnProd['return'];
        }else{ //FOI BEM SUCEDIDO
            if( ($anymarketproductsUpdt->getData('nmp_sku') == null) || ($StoreIDAmProd != $storeID) ) {
                $anymarketproducts = Mage::getModel('db1_anymarket/anymarketproducts');
                $anymarketproducts->setNmpId($product->getId());
                $anymarketproducts->setNmpSku($product->getSku());
                $anymarketproducts->setNmpName($product->getName());
                $anymarketproducts->setNmpDescError("");
                $anymarketproducts->setNmpStatusInt("Integrado");
                $anymarketproducts->setStatus("1");
                $anymarketproducts->setStores(array($storeID));
                $anymarketproducts->save();
            }else{
                $anymarketproductsUpdt->setNmpId($product->getId());
                $anymarketproductsUpdt->setNmpSku($product->getSku());
                $anymarketproductsUpdt->setNmpName($product->getName());
                $anymarketproductsUpdt->setNmpDescError("");
                $anymarketproductsUpdt->setNmpStatusInt("Integrado");
                $anymarketproductsUpdt->setStatus("1");
                $anymarketproductsUpdt->setStores(array($storeID));
                $anymarketproductsUpdt->save();
            }

            $returnMet = $returnProd['return'];
        }

        $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
        if(is_string($returnMet)){
            $anymarketlog->setLogDesc( $returnMet );
        }else{
            $anymarketlog->setLogDesc( json_encode($returnMet) );
        }

        $anymarketlog->setLogId( $product->getSku() );
        if(is_string($returnProd['json'])){
            $anymarketlog->setLogJson( $returnProd['json'] );
        }else{
            $anymarketlog->setLogJson( json_encode($returnProd['json']) );
        }

        $anymarketlog->setStatus($priority);
        $anymarketlog->setStores(array($storeID));
        $anymarketlog->save();
    }

    /**
     * @param $var_1
     * @param $var_2
     * @return array
     */
    private function compareArrayImage($var_1, $var_2){
        $arrayReturn = array();
        foreach ($var_1 as $value_1) {
            $hSamVal = false;
            foreach ($var_2 as $value_2) {
                $ArrtratValue1 = explode("_", $value_1['ctrl']);
                $ArrtratValue2 = explode("_", $value_2['ctrl']);

                $tratval1 = array_values($ArrtratValue1);
                $tratValue1 = array_shift($tratval1);
                $tratval2 = array_values($ArrtratValue2);
                $tratValue2 = array_shift($tratval2);
                if( $tratValue1 == $tratValue2 ){
                    $hSamVal = true;
                    break;
                }
            }
            if(!$hSamVal){
                $arrayReturn[] = $value_1;
            }
        }
        return $arrayReturn;
    }

    /**
     * create a Configurable Product
     *
     * @param $storeID
     * @param $dataProdConfig
     * @param $simpleProducts
     * @param $AttributeIds
     * @return Mage_Catalog_Model_Product
     */
    private function create_configurable_product($storeID, $dataProdConfig, $simpleProducts, $AttributeIds){
        $productGenerator = Mage::helper('db1_anymarket/productgenerator');
        $product = $productGenerator->createConfigurableProduct($storeID, $dataProdConfig, $simpleProducts, $AttributeIds);

        $returnProd['return'] = Mage::helper('db1_anymarket')->__('Configurable product Created').' ('.$product->getId().')';
        $returnProd['error'] = '0';
        $returnProd['json'] = '';

        $this->saveLogsProds($storeID, "0", $returnProd, $product);

        return $product;
    }

    /**
     * update a Configurable Product
     *
     * @param $storeID
     * @param $idProd
     * @param $dataProdConfig
     * @param $simpleProducts
     * @return Mage_Catalog_Model_Product
     */
    private function update_configurable_product($storeID, $idProd, $dataProdConfig, $simpleProducts){
        $productsIDs = array();
        foreach ($simpleProducts as $prodVal) {
            array_push($productsIDs, $prodVal['Id']);
        }

        $productGenerator = Mage::helper('db1_anymarket/productgenerator');
        $product = $productGenerator->updateConfigurableProduct($storeID, $idProd, $dataProdConfig, $productsIDs);

        $returnProd['return'] = Mage::helper('db1_anymarket')->__('Configurable product Updated').' ('.$product->getSku().')';
        $returnProd['error'] = '0';
        $returnProd['json'] = '';
        $this->saveLogsProds($storeID, "0", $returnProd, $product);

        return $product;
    }
    /**
     * create simple prod in magento
     *
     * @param $storeID
     * @param $data
     * @return Mage_Catalog_Model_Product
     */
    function create_simple_product($storeID, $data){
        $productGenerator = Mage::helper('db1_anymarket/productgenerator');
        $product = $productGenerator->createSimpleProduct($data);

        if(!$product){
            $returnProd['return'] = Mage::helper('db1_anymarket')->__('Simple product Created').' ('.$product->getId().')';
            $returnProd['error'] = '0';
            $returnProd['json'] = '';

            $this->saveLogsProds($storeID, "0", $returnProd, $product);
        }

        return $product;
    }

    /**
     * @param $attrCode
     * @param $attrVal
     * @param $typeProc
     * @return mixed
     */
    public function procAttrConfig($attrCode, $attrVal, $typeProc){
        $_product = Mage::getModel('catalog/product');
        $attr = $_product->getResource()->getAttribute($attrCode);

        if($attr){
            if ($attr->usesSource()) {
                if($typeProc == 0){
                    $returnAttr = $attr->getSource()->getOptionId((string)$attrVal);
                    if($returnAttr){
                        return $returnAttr;
                    }else{
                        return $attrVal;
                    }
                }else{
                    $returnAttr = $attr->getSource()->getOptionText($attrVal);
                    if($returnAttr){
                        return $returnAttr;
                    }else{
                        return $attrVal;
                    }
                }
            }else{
                return $attrVal;
            }
        }else{
            return $attrVal;
        }

    }

    /**
     * update image for specific product
     *
     * @param $Prod
     * @param $ProdsJSON
     * @param $idClient
     */
    public function update_image_product($Prod, $ProdsJSON, $idClient){
        $arrSku = $ProdsJSON->skus;
        $variation = array();
        foreach ($arrSku as $skuImg) {
            if($skuImg->idInClient == $Prod->getSku() ){
                foreach ($skuImg->variations as $variationSku) {
                    array_push($variation, $variationSku->description);
                }
                break;
            }
        }

        //OBTEM IMAGENS DO MAGENTO
        $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
        $items = $mediaApi->items($Prod->getId());
        $imagesGalleryMG = array();
        foreach($items as $item) {
            $crltImg = basename($item['file']);
            $crltImg = str_replace(strrchr($crltImg,"."), "", $crltImg);
            $imagesGalleryMG[] = array('ctrl' => $crltImg, 'img' => $item['url'], 'file' => $item['file'] );
        }

        //OBTEM IMAGENS DO ANYMARKET
        $imagesGalleryAM = array();
        foreach ($ProdsJSON->photos as $image) {
            $crltImgAM = $image->original;
            $crltImgAM = str_replace(strrchr($crltImgAM,"."), "", $crltImgAM);

            $urlImage = null;
            if( isset($image->url) ){
                $urlImage = $image->url;
            }elseif( isset($image->standardUrl) ){
                $urlImage = $image->standardUrl;
            }elseif( isset($image->original) ){
                $urlImage = $image->original;
            }

            if( $urlImage != null ){
                if( !empty($variation) ){
                    if (in_array( $image->variationValue, $variation)) {
                        $imagesGalleryAM[] = array('ctrl' => md5($crltImgAM . $idClient), 'img' => $urlImage, 'main' => $image->main);
                    }
                }else{
                    $imagesGalleryAM[] = array('ctrl' => md5($crltImgAM . $idClient), 'img' => $urlImage, 'main' => $image->main);
                }
            }
        }

        //COMPARA IMG AM COM MG SE TIVER DIVERCIA REMOVE DO PRODUTO
        $diffMG = $this->compareArrayImage($imagesGalleryMG, $imagesGalleryAM);
        if ($diffMG) {
            foreach ($diffMG as $diffMG_value) {
                $mediaApi->remove($Prod->getId(), $diffMG_value['file']);
            }
        }

        //COMPARA IMG AM COM MG SE TIVER DIVERGENCIA ADD NO PRODUTO
        $diffAM = $this->compareArrayImage($imagesGalleryAM, $imagesGalleryMG);
        if ($diffAM) {
            foreach ($diffAM as $diffAM_value) {
                $imagesGallery[] = array('img' => $diffAM_value['img'], 'main' => $diffAM_value['main']);
            }

            $dataImgs = array('images' => $imagesGallery, 'sku' => $idClient);
            $productGenerator = Mage::helper('db1_anymarket/productgenerator');
            $productGenerator->updateImages($Prod, $dataImgs);
        }
    }

    /**
     * @param $product
     * @param $skusParam
     * @param $storeID
     *
     * @return Boolean
     */
    //TODO OBTER O ID DO OBJETO E CONTINUAR DAQUI
    public function sendImageSkuToAnyMarket($storeID, $product, $skusParam) {
        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $headers = array(
            "Content-type: application/json",
            "Cache-Control: no-cache",
            "gumgaToken: ".$TOKEN
        );

        // SINCRONIZA AS FOTOS E SKUS
        $idAnymarket = $this->getIdInAnymarketBySku($storeID, $product);
        if( $idAnymarket == null ){
            return false;
        };

        $skusProd = $this->CallAPICurl("GET", $HOST . "/v2/products/" . $idAnymarket . "/skus", $headers, null);
        if ($skusProd['error'] != '0') {
            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlog->setLogDesc('Error on get Images Anymarket from sku.');
            $anymarketlog->setLogJson($skusProd['json']);
            $anymarketlog->setLogId($product->getSku());
            $anymarketlog->setStatus("1");
            $anymarketlog->setStores(array($storeID));
            $anymarketlog->save();

            return false;
        }

        if($product->getTypeID() == "configurable" && $product->getData('integra_images_root_anymarket') == 1 ){
            //obtem as imagens do produto(Config)
            Mage::helper('db1_anymarket/image')->sendImageToAnyMarket($storeID, $product, null);
        }

        foreach ($skusParam as $skuPut) {
            $prodSimple = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', $skuPut['partnerId']);
            $paramSku = array(
                "title" => $skuPut['title'],
                "partnerId" => $skuPut['partnerId'],
                "ean" => $skuPut['ean'],
                "amount" => $skuPut['amount'],
                "price" => $skuPut['price'],
            );

            if (isset($skuPut['variations'])) {
                foreach ($skuPut['variations'] as $variationPut) {
                    Mage::helper('db1_anymarket/image')->sendImageToAnyMarket($storeID, $prodSimple, $variationPut);
                }
                $paramSku['variations'] = $skuPut['variations'];
            } else {
                Mage::helper('db1_anymarket/image')->sendImageToAnyMarket($storeID, $product, null);
            }

            $flagHSku = '';
            if (isset($skusProd['return'])) {
                foreach ($skusProd['return'] as $skuAM) {
                    if ($skuAM->partnerId == $prodSimple->getSku()) {
                        $flagHSku = $skuAM->id;
                        break;
                    }
                }
            }

            if ($flagHSku != '') {
                $skuProdReturn = $this->CallAPICurl("PUT", $HOST."/v2/products/".$idAnymarket."/skus/".$flagHSku, $headers, $paramSku);

                if ($skuProdReturn['error'] == '0') {
                    $skuProdReturn['return'] = Mage::helper('db1_anymarket')->__('SKU Updated').' (' . $skuPut['partnerId'] . ')';
                }
            } else {
                $skuProdReturn = $this->CallAPICurl("POST", $HOST . "/v2/products/".$idAnymarket."/skus", $headers, $paramSku);

                if ($skuProdReturn['error'] == '0') {
                    $skuProdReturn['return'] = Mage::helper('db1_anymarket')->__('SKU Created') . ' (' . $skuPut['partnerId'] . ')';
                }
            }
            $this->saveLogsProds($storeID, "1", $skuProdReturn, $prodSimple);
        }
    }

    /**
     * @param $descBrand
     * @param $storeID
     * @return integer
     */
    public function getBrandForProduct($storeID, $descBrand){
        $brand = Mage::getModel('db1_anymarket/anymarketbrands')->load($descBrand, 'brd_name');
        if( $brand->getData('brd_id') == null ){
            $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
            $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

            $headers = array(
                "Content-type: application/json",
                "Accept: */*",
                "gumgaToken: ".$TOKEN
            );

            $param = array(
                "name" => $descBrand,
                "partnerId" => $descBrand
            );

            $returnBrands = $this->CallAPICurl("POST", $HOST."/v2/brands", $headers, $param);
            $brandsJSON = $returnBrands['return'];
            $return = null;
            if( $returnBrands['error'] == '0' ){
                $return = $brandsJSON->id;
            }else{
                if( isset($brandsJSON->data->brandId) ){
                    $return = $brandsJSON->data->brandId;
                }
            }

            if( $return ){
                $mBrands = Mage::getModel('db1_anymarket/anymarketbrands');
                $mBrands->setBrdId( $return );
                $mBrands->setBrdName( $descBrand );
                $mBrands->setStatus("1");
                $mBrands->setStores(array($storeID));
                $mBrands->save();

                return $return;
            }else{
                return '';
            }

        }else{
            return $brand->getData('brd_id');
        }
    }

    public function getCustomsVariations($storeID, $product){
        $customVariation = Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_custom_variation_field', $storeID);
        $variationReturn = array();
        if ($customVariation && $customVariation != 'a:0:{}') {
            $customVariation = unserialize($customVariation);
            if (is_array($customVariation)) {
                foreach($customVariation as $customVariationRow) {
                    $attrMG = $customVariationRow['attrMGVariation'];
                    $vartAM = $customVariationRow['variationTypeAnymarket'];

                    $attrValueMG = $this->procAttrConfig($attrMG, $product->getData($attrMG), 1);
                    if( $attrValueMG != '' && $attrValueMG != null ){
                        $variationReturn[$vartAM] = $attrValueMG;
                    }

                }
            }
        }

        return $variationReturn;
    }

    public function prepareForSendProduct($storeID, $product){
        $typeSincProd = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_prod_sync_field', $storeID);
        if( $typeSincProd != 0 ) {
            return false;
        }

        if( $product->getData('integra_anymarket') != 1 ){
            return false;
        }

        $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        $stockQty = $stock->getQty();
        if($product->getTypeID() == "configurable"){
            //PRODUTO CONFIGURAVEL
            Mage::getModel('catalog/product_type_configurable')->getProduct($product)->unsetData('_cache_instance_products');
            $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $product);
            if(count($childProducts) > 0){
                Mage::getSingleton('core/session')->setImportProdsVariable('false');
                foreach ($childProducts as $prodCh) {
                    $productChild = Mage::getModel('catalog/product')->setStoreId($storeID)->load($prodCh->getId());
                    if( $productChild->getData('integra_anymarket') != 1 ){
                        $productChild->setData('integra_anymarket', $product->getData('integra_anymarket') );
                        $productChild->save();
                    }
                }
                Mage::getSingleton('core/session')->setImportProdsVariable('true');

                $this->sendProductToAnyMarket($storeID, $product->getId());
            }
        }else{
            $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild( $product->getId() );

            $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
            $ean    = Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_ean_field', $storeID);

            if($filter == 'final_price'){
                $stkPrice = $product->getFinalPrice();
            }else{
                $stkPrice = $product->getData($filter);
            }

            if($parentIds){
                //PRODUTO SIMPLES FILHO DE UM CONFIG
                $attributeOptions = array();
                foreach ($parentIds as $parentId) {
                    $productConfig = Mage::getModel('catalog/product')->load($parentId);

                    if( $productConfig->getId() ) {
                        foreach ($productConfig->getTypeInstance()->getConfigurableAttributes() as $attribute) {
                            $value = $product->getAttributeText($attribute->getProductAttribute()->getAttributeCode());
                            $attributeOptions[$attribute->getLabel()] = $value;
                        }

                        $customVariation = $this->getCustomsVariations($storeID, $product);
                        foreach ($customVariation as $index => $value) {
                            $attributeOptions[$index] = $value;
                        }

                        foreach ($parentIds as $parentId) {
                            $arrSku = array(
                                "price" => $stkPrice,
                                "amount" => $stockQty,
                                "ean" => $product->getData($ean),
                                "partnerId" => $product->getSku(),
                                "title" => $product->getName(),
                                "internalIdProduct" => $product->getId()
                            );

                            if( $productConfig->getData('exp_sep_simp_prod') != 1 ) {
                                $arrSku["variations"] = $attributeOptions;
                                $this->sendImageSkuToAnyMarket($storeID, $product, array($arrSku));
                            }else{
                                $this->sendProductToAnyMarket($storeID, $product->getId());
                                $this->updatePriceStockAnyMarket($storeID, $product->getId(), $stockQty, $product->getData($filter));
                            }

                        }
                    }else{
                        $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                        $anymarketlog->setLogDesc('Produto possui registro de um Parent, porem esse parent não existe no Magento');
                        $anymarketlog->setLogId($product->getSku());
                        $anymarketlog->setStatus("1");
                        $anymarketlog->setStores(array($storeID));
                        $anymarketlog->save();

                        //PRODUTO FILHO DE UM PAI QUE AGORA EH SIMPLES
                        $this->sendProductToAnyMarket($storeID, $product->getId());
                        $this->updatePriceStockAnyMarket($storeID, $product->getId(), $stockQty, $product->getData($filter));
                    }
                }
            }else{
                //PRODUTO SIMPLES E OUTROS
                $this->sendProductToAnyMarket($storeID, $product->getId());
                $this->updatePriceStockAnyMarket($storeID, $product->getId(), $stockQty, $product->getData($filter));
            }

        }
    }

    /**
     * send products to AnyMarket
     *
     * @param $storeID
     * @param $arrIdProduct
     * @return bool
     */
    public function sendProductSepAnymarket($storeID, $arrIdProduct){
        foreach ($arrIdProduct as $idProduct) {
            $this->sendProductToAnyMarket($storeID, $idProduct);
        }

        return false;
    }

    /**
     * send product to AnyMarket
     *
     * @param $storeID
     * @param $idProduct
     * @return bool
     */
    public function sendProductToAnyMarket($storeID, $idProduct){
        //obter configuracoes
        $product = Mage::getModel('catalog/product')->setStoreId($storeID)->load($idProduct);

        //Obtem os parametros dos attr para subir para o AM
        $model =              Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_model_field', $storeID);
        $brand =              Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_brand_field', $storeID);

        $volume_comprimento = Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_vol_comp_field', $storeID);
        $volume_altura =      Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_vol_alt_field', $storeID);
        $volume_largura =     Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_vol_larg_field', $storeID);
        $video_url =          Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_video_url_field', $storeID);
        $nbm        =         Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_nbm_field', $storeID);
        $nbm_origin =         Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_nbm_origin_field', $storeID);
        $ean =                Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_ean_field', $storeID);
        $warranty_text =      Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_warranty_text_field', $storeID);
        $warranty_time =      Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_warranty_time_field', $storeID);

        $price_factor =       Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_price_factor_field', $storeID);
        $calculated_price =   Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_calculated_price_field', $storeID);

        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $idProductAnymarket = $this->getIdInAnymarketBySku($storeID, $product);
        $arrProd = array();
        $varPriceFactor = '';
        $categProd = null;
        if($product->getData('exp_sep_simp_prod') != 1 || $product->getTypeID() == "simple" ) {
            // verifica categoria null ou em branco
            $categProd = $product->getData('categoria_anymarket');
            if ($categProd == null || $categProd == '') {
                $categProd = Mage::helper('db1_anymarket/category')->valideCategoryToSend($HOST, $TOKEN, $product->getId());
                if(!$categProd) {
                    array_push($arrProd, Mage::helper('db1_anymarket')->__('AnyMarket Category'));
                }
            }

            // verifica o Price Factor (Markup)
            $varPriceFactor = $this->procAttrConfig($price_factor, $product->getData($price_factor), 1);
            if ((string)(float)$varPriceFactor == $varPriceFactor) {
                $varPriceFactor = (float)$varPriceFactor;
                if ($varPriceFactor > 99) {
                    array_push($arrProd, Mage::helper('db1_anymarket')->__('AnyMarket Price Factor(Limit 99)'));
                }
            } else {
                array_push($arrProd, Mage::helper('db1_anymarket')->__('AnyMarket Price Factor(Only Number)'));
            }

            // verifica Origin null ou em branco
            $originData = $this->procAttrConfig($nbm_origin, $product->getData($nbm_origin), 1);
            if ($originData == null || $originData == '') {
                array_push($arrProd, Mage::helper('db1_anymarket')->__('AnyMarket Origin'));
            }

            //trata para nao enviar novamente solicitacao quando o erro for o mesmo
            if ($idProductAnymarket == null) {
                $prodErrorCtrl = Mage::getModel('db1_anymarket/anymarketproducts')->setStoreId($storeID)
                    ->load($product->getId(), 'nmp_id');
                if ($prodErrorCtrl->getData('nmp_id') != null) {
                    $descError = $prodErrorCtrl->getData('nmp_desc_error');

                    // Trata para nao ficar disparando em cima da Duplicadade de SKU
                    $mesgDuplSku = strrpos($descError, "Duplicidade de SKU:");
                    if ($mesgDuplSku !== false) {
                        $bindProds = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_bind_product_field', $storeID);
                        if ($bindProds == '0') {
                            $oldSkuErr = $this->getBetweenCaract($descError, '"', '"');

                            if ($oldSkuErr == $product->getSku()) {
                                array_push($arrProd, 'Duplicidade de SKU: ' . Mage::helper('db1_anymarket')->__('Already existing SKU in anymarket') . ' "' . $oldSkuErr . '".');
                            }
                        }
                    }
                }
            }
        }

        if( !empty($arrProd) ){
            $returnProd['error'] = '1';
            $returnProd['json'] = '';

            $emptyFields = ' ';
            foreach ($arrProd as $field) {
                $emptyFields .= $field.', ';
            }

            $returnProd['return'] = Mage::helper('db1_anymarket')->__('Product with inconsistency:').$emptyFields;
            $this->saveLogsProds($storeID, "0", $returnProd, $product);

            return false;
        }else{
            $arrProd = array();

            $MassUnit = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_weight_field', $storeID);
            $UnitMeasurement = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_size_field', $storeID);

            //verifica se o produto e configurable
            $confID = "";
            $Weight = "";
            if($product->getTypeID() == "configurable"){
                $confID = $product->getId();
                if( $product->getData('integra_images_root_anymarket') == 1 ) {
                    //obtem as imagens do produto(Config)
                    $itemsIMG = Mage::helper('db1_anymarket/image')->getImagesOfProduct($storeID, $product, null);
                }
            }else{
                // verifica se é um simples pertecente a um Configurable
                $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild( $product->getId() );
                $Weight = ($product->getTypeID() == "bundle" && $product->getPriceType() == 0 ) ? $this->getWeightOfBundle($storeID, $product) : $product->getWeight();
                if (isset($parentIds[0])) {
                    $confID = $parentIds[0];
                    $productConfig = Mage::getModel('catalog/product')->setStoreId($storeID)->load($confID);
                    $exportSimpleSep = $productConfig->getData('exp_sep_simp_prod');
                    if($exportSimpleSep != 1){
                        $product = $productConfig;
                    }else{
                        $confID = "";
                    }

                }

                //obtem as imagens do produto(Simples)
                $itemsIMG = Mage::helper('db1_anymarket/image')->getImagesOfProduct($storeID, $product, null);
            }

            //obtem os produtos configs - verifica se e configurable
            $ArrSimpleConfigProd = array();
            if($confID != ""){
                $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $product);
                $attributesConf = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);

                $exportSimpleSep = $product->getData('exp_sep_simp_prod');
                $arrSepProd = array();
                foreach($childProducts as $child) {
                    $SimpleConfigProd = Mage::getModel('catalog/product')->setStoreId($storeID)->load($child->getId());

                    if( $exportSimpleSep == 1 ){
                        array_push( $arrSepProd, $child->getId());
                        continue;
                    }

                    if ($Weight == "") {
                        $Weight = $SimpleConfigProd->getWeight();
                    }

                    //obtem os atributos do configuravel
                    $qtyStore = $this->getAllStores();
                    if (count($qtyStore) > 1) {
                        $storeIDAttrVar = $storeID;
                    } else {
                        $fArr = array_shift($qtyStore);
                        $storeIDAttrVar = $fArr['store_id'];
                    }

                    $ArrVariationValues = array();
                    foreach ($attributesConf as $attribute) {
                        $options = Mage::getResourceModel('eav/entity_attribute_option_collection');
                        $valuesAttr = $options->setAttributeFilter($attribute['attribute_id'])
                            ->setStoreFilter($storeIDAttrVar)
                            ->toOptionArray();

                        foreach ($valuesAttr as $value) {
                            $childValue = $child->getData($attribute['attribute_code']);
                            if ($value['value'] == $childValue) {
                                $ArrVariationValues[$attribute['store_label']] = $value['label'];
                            }
                        }
                    }

                    $customVariation = $this->getCustomsVariations($storeID, $SimpleConfigProd);
                    foreach ($customVariation as $index => $value) {
                        $ArrVariationValues[$index] = $value;
                    }

                    //obtem as imagens do produto (Obtem os simples e relaciona as variacoes)
                    $itemsIMGSimple = Mage::helper('db1_anymarket/image')->getImagesOfProduct($storeID, $SimpleConfigProd, $ArrVariationValues);
                    if( isset($itemsIMG) &&  count($itemsIMG) > 0 ){
                        foreach ($itemsIMGSimple as $itemImg){
                            if( !in_array($itemImg, $itemsIMG) ){
                                array_push($itemsIMG, $itemImg);
                            }
                        }
                    }else{
                        $itemsIMG = $itemsIMGSimple;
                    }

                    $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));

                    if ($filter == 'final_price') {
                        $stkPrice = $SimpleConfigProd->getFinalPrice();
                    } else {
                        $stkPrice = $SimpleConfigProd->getData($filter);
                    }

                    $simpConfProdSku = $SimpleConfigProd->getSku();
                    // verificacao dos dados de price
                    if (($stkPrice == null) || ($stkPrice == '') || ((float)$stkPrice <= 0)) {
                        array_push($arrProd, 'Price (' . $simpConfProdSku . ')');
                    }

                    // verificacao dos dados de SKU
                    $cValid = array('.', '-', '_');

                    if (!ctype_alnum(str_replace($cValid, '', $simpConfProdSku))) {
                        array_push($arrProd, 'SKU (' . $simpConfProdSku . ')');
                    }

                    $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($SimpleConfigProd);
                    if ($SimpleConfigProd->getData('integra_anymarket') == 1 && $SimpleConfigProd->getStatus() == 1){
                        $ArrSimpleConfigProd[] = array(
                            "variations" => $ArrVariationValues,
                            "price" => $stkPrice,
                            "amount" => $stock->getQty(),
                            "ean" => $SimpleConfigProd->getData($ean),
                            "partnerId" => $simpConfProdSku,
                            "title" => $SimpleConfigProd->getName(),
                            "internalIdProduct" => $SimpleConfigProd->getId(),
                        );
                    }

                }

                if( count($arrSepProd) > 0 ){
                    return $this->sendProductSepAnymarket($storeID, $arrSepProd);
                }
            }

            //ajusta o array de skus
            if( count($ArrSimpleConfigProd) <= 0 ){
                $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);

                $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));

                if ($filter == 'final_price') {
                    $stkPrice = $product->getFinalPrice();
                } else {
                    $stkPrice = $product->getData($filter);
                }

                if($product->getTypeID() == "bundle" && $product->getPriceType() == 0 && $stkPrice == null) {
                    $priceModel = $product->getPriceModel();
                    $PricesBundle = $priceModel->getTotalPrices($product, null, true, false);
                    $stkPrice = reset($PricesBundle);
                }

                $prodSkuJ = $product->getSku();

                // verificacao dos dados de price
                if(($stkPrice == null) || ($stkPrice == '')  || ((float)$stkPrice <= 0)){
                    array_push($arrProd, 'Price ('.$prodSkuJ.')');
                }

                // verificacao dos dados de SKU
                $cValid = array('.', '-', '_');
                if(!ctype_alnum(str_replace($cValid, '', $prodSkuJ))) {
                    array_push($arrProd, 'SKU ('.$prodSkuJ.')');
                }

                $ArrSimpleConfigProd[] = array(
                    "price" => $stkPrice,
                    "amount" => $stock->getQty(),
                    "ean" => $product->getData($ean),
                    "partnerId" => $prodSkuJ,
                    "title" => $product->getName(),
                    "internalIdProduct" => $product->getId(),
                );
            }

            //cria os custom attributes
            $attributeSetModel = Mage::getModel("eav/entity_attribute_set");
            $attributeSetModel->load($product->getAttributeSetId());
            $attributeSetName  = $attributeSetModel->getAttributeSetId();

            $attributes = Mage::getResourceModel('catalog/product_attribute_collection')
                ->setAttributeSetFilter($attributeSetName)
                ->getItems();

            $ArrAttributes = array();
            $contIndexAttr = 0;
            foreach ($attributes as $attribute){
                $attrCheck =  Mage::getModel('db1_anymarket/anymarketattributes')->load($attribute->getAttributeId(), 'nma_id_attr');
                if($attrCheck->getData('nma_id_attr') == null) {
                    continue;
                }
                if( ($attrCheck->getData('status') != 1) ||
                    ( $attribute->getAttributeCode() == $model ) ||
                    ($this->checkArrayAttributes( $ArrAttributes, "description", $attribute->getFrontendLabel() ) )
                ) {
                    continue;
                }
                if($confID == ""){
                    if(!$this->checkArrayAttributes($ArrAttributes, "name", $attribute->getFrontendLabel())) {
                        continue;
                    }
                    $valAttr = $this->procAttrConfig($attribute->getAttributeCode(), $product->getData($attribute->getAttributeCode()), 1);
                    if ($valAttr != null || $valAttr != '') {
                        $ArrAttributes[] = array("index" => $contIndexAttr, "name" => $attribute->getFrontendLabel(), "value" => $valAttr);
                        $contIndexAttr = $contIndexAttr + 1;
                    }
                }else{
                    foreach ($attributesConf as $attributeConf){
                        if(in_array($attribute->getAttributeCode(), $attributeConf)) {
                            continue;
                        }
                        if(!$this->checkArrayAttributes($ArrAttributes, "name", $attribute->getFrontendLabel())){
                            $valAttr = $this->procAttrConfig($attribute->getAttributeCode(), $product->getData( $attribute->getAttributeCode() ), 1);
                            if( $valAttr != null || $valAttr != '' ){
                                $ArrAttributes[] = array("index" => $contIndexAttr, "name" => $attribute->getFrontendLabel(), "value" => $valAttr);
                                $contIndexAttr = $contIndexAttr+1;
                            }
                        }
                    }
                }
            }

            //trata as dimensoes
            $vHeight = $this->procAttrConfig($volume_altura, $product->getData( $volume_altura ), 1);
            $vWidth  = $this->procAttrConfig($volume_largura, $product->getData( $volume_largura ), 1);
            $vLength = $this->procAttrConfig($volume_comprimento, $product->getData( $volume_comprimento ), 1);
            if( $product->getTypeID() == "bundle" ){
                if( ($vHeight == "") || ($vWidth == "") || ($vLength == "") ) {
                    $arrDim = $this->getDimensionsOfBundle($storeID, $product, $volume_altura, $volume_largura, $volume_comprimento);

                    $vHeight = $arrDim['height'];
                    $vWidth = $arrDim['width'];
                    $vLength = $arrDim['length'];
                }
            }

            $param = array(
                "id" => $idProductAnymarket,
                "title" => $product->getName(),
                "description" => $this->getFullDescription($storeID, $product),
                "nbm" => array(
                    "id" => $this->procAttrConfig($nbm, $product->getData( $nbm ), 1)
                ),
                "brand" => array(
                    "id" => $this->getBrandForProduct($storeID, $this->procAttrConfig($brand, $product->getData( $brand ), 1)),
                    "name" => $this->procAttrConfig($brand, $product->getData( $brand ), 1)
                ),
                "origin" => array(
                    "id" => $this->procAttrConfig($nbm_origin, $product->getData( $nbm_origin ), 1)
                ),
                "category" => array(
                    "id" => $categProd
                ),
                "model" =>  $this->procAttrConfig($model, $product->getData( $model ), 1),
                "warrantyText" => $this->procAttrConfig($warranty_text, $product->getData( $warranty_text ), 1),
                "warrantyTime" => $this->procAttrConfig($warranty_time, $product->getData( $warranty_time ), 1),
                "weight" => $MassUnit == 0 ? $Weight/1 : $Weight/1000,
                "height" => $this->convertUnitMeasurement($UnitMeasurement, $vHeight, 1),
                "width"  => $this->convertUnitMeasurement($UnitMeasurement, $vWidth,  1),
                "length" => $this->convertUnitMeasurement($UnitMeasurement, $vLength, 1),
                "images" => $this->unique_multidim_array($itemsIMG, 'url'),
                "priceFactor" => $varPriceFactor,
                "calculatedPrice" => $product->getData( $calculated_price ) == 0 ? false : true,
                "characteristics" => $ArrAttributes,
                "skus" => $ArrSimpleConfigProd,
            );

            $headers = array(
                "Content-type: application/json",
                "Cache-Control: no-cache",
                "gumgaToken: ".$TOKEN
            );

            $varVideoURL = $this->procAttrConfig($video_url, $product->getData( $video_url ), 1);
            if($varVideoURL && $varVideoURL != ""){
                $param["videoUrl"] = $varVideoURL;
            }

            if( !empty($arrProd) ){
                $returnProd['error'] = '1';
                $returnProd['json'] = '';

                $emptyFields = ' ';
                foreach ($arrProd as $field) {
                    $emptyFields .= $field.', ';
                }

                $returnProd['return'] = Mage::helper('db1_anymarket')->__('Product with inconsistency:').' '.$emptyFields;
                $this->saveLogsProds($storeID, "0", $returnProd, $product);
                return false;
            }else{
                if( $idProductAnymarket == null ){
                    $returnProd = $this->CallAPICurl("POST", $HOST."/v2/products/", $headers, $param);

                    if($returnProd['error'] == '1'){
                        $this->saveLogsProds($storeID, "1", $returnProd, $product);
                        $this->saveCallbackReceiver( $product->getSku() );
                    }
                }else{
                    $returnProd = $this->CallAPICurl("PUT", $HOST."/v2/products/".$idProductAnymarket, $headers, $param);
                    if($returnProd['error'] == '0'){
                        $returnProd['return'] = Mage::helper('db1_anymarket')->__('Product Updated');

                        $this->saveCallbackReceiver( $product->getSku() );
                    }

                    //ADICIONA UM NOVO SKU
                    foreach ($ArrSimpleConfigProd as $skuPut) {
                        $skuProdReturn = $this->CallAPICurl("POST", $HOST."/v2/products/".$idProductAnymarket."/skus", $headers, $skuPut);
                        if($skuProdReturn['error'] == '0'){
                            $skuProdReturn['return'] = Mage::helper('db1_anymarket')->__('SKU Created').' ('.$skuPut['partnerId'].')';
                            $this->saveLogsProds($storeID, "1", $skuProdReturn, $product);

                            $this->saveCallbackReceiver( $product->getSku() );
                        }else{
                            $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
                            $productSku = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', $skuPut['partnerId'] );
                            if ( $productSku != null && $productSku->getId() != null && $productSku->getData() != null ) {
                                $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productSku);
                                $this->updatePriceStockAnyMarket($storeID, $productSku->getId(), $stock->getQty(), $productSku->getData($filter));
                            }
                        }
                    }

                    $this->sendImageSkuToAnyMarket($storeID, $product, $param['skus']);
                    $this->saveLogsProds($storeID, "1", $returnProd, $product);
                }
                return true;
            }
        }

    }

    /**
     * @param $HOST
     * @param $headers
     * @param $IDTransmission
     * @param $statusTransmission
     * @param $tokenTransmissions
     */
    private function changeStatusTransmission($HOST, $headers, $IDTransmission, $statusTransmission, $tokenTransmissions){
        $params = array(
            "marketPlaceStatus" => $statusTransmission." Sincronizado"
        );

        $returnChangeTrans = $this->CallAPICurl("PUT", $HOST."/v2/transmissions/".$IDTransmission, $headers, $params);
        if($returnChangeTrans['error'] == '1'){
            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlog->setLogDesc( Mage::helper('db1_anymarket')->__('Error on change transmissions status: '). $returnChangeTrans['return'] );
            $anymarketlog->setStatus("0");
            $anymarketlog->save();
        }

        if($tokenTransmissions != 'notoken'){
            $paramsFeeds = array(
                "token" => $tokenTransmissions
            );

            $returnChangeTrans = $this->CallAPICurl("PUT", $HOST."/v2/transmissions/feeds/".$IDTransmission, $headers, $paramsFeeds);
            if($returnChangeTrans['error'] == '1'){
                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( Mage::helper('db1_anymarket')->__('Error update feeds transmissions'));
                $anymarketlog->setStatus("1");
                $anymarketlog->save();
            }
        }

    }

    /**
     * get only product in feed of AnyMarket
     */
    public function getFeedProdsFromAnyMarket($storeID){
        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $headers = array(
            "Content-type: application/json",
            "Accept: */*",
            "gumgaToken: ".$TOKEN
        );

        $returnFeedTrans = $this->CallAPICurl("GET", $HOST."/v2/transmissions/feeds?limit=100", $headers, null);

        if($returnFeedTrans['error'] == '1'){
            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlog->setLogDesc( Mage::helper('db1_anymarket')->__('Error on get transmissions feed '). $returnFeedTrans['return'] );
            $anymarketlog->setStatus("0");
            $anymarketlog->save();
        }else{
            $prodCreated = $this->getSpecificFeedProduct($storeID, $returnFeedTrans['return'], $headers, $HOST);

            // TRATA STOCK
            if ( $prodCreated ) {
                $typeSincOrder = Mage::getStoreConfig('anymarket_section/anymarket_integration_order_group/anymarket_type_order_sync_field', $storeID);
                if ($typeSincOrder == 1) {
                    $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
                    $ProdStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($prodCreated);
                    $this->updatePriceStockAnyMarket($storeID, $prodCreated->getId(), $ProdStock->getQty(), $prodCreated->getData($filter));
                }
            }
        }
    }

    /**
     * @param $IDProd
     * @param $storeID
     * @return Boolean
     */
    public function getStockProductAnyMarket($storeID, $IDProd){
        $product = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $IDProd );

        $HOST = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);
        $idAnymarket = $this->getIdInAnymarketBySku($storeID, $product);
        if( $idAnymarket == null ) {
            return false;
        }
        $headers = array(
            "Content-type: application/json",
            "Accept: */*",
            "gumgaToken: " . $TOKEN
        );

        $returnProdSpecific = $this->CallAPICurl("GET", $HOST."/v2/products/".$idAnymarket, $headers, null);
        if ($returnProdSpecific['error'] != '0') {
            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlog->setLogDesc("Erro on get stock " . $returnProdSpecific['return']);
            $anymarketlog->setLogId($IDProd);
            $anymarketlog->setStatus("0");
            $anymarketlog->setStores(array($storeID));
            $anymarketlog->save();
            return false;
        }

        $ProdsJSON = $returnProdSpecific['return'];
        foreach ($ProdsJSON->skus as $sku) {
            $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
            $stockItem->setData('is_in_stock', $sku->amount > 0 ? '1' : '0');
            $stockItem->setData('qty', $sku->amount);
            $stockItem->save();

            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlog->setLogDesc(Mage::helper('db1_anymarket')->__('Imported stock SKU: ') . $product->getData('sku'));
            $anymarketlog->setLogId($product->getId());
            $anymarketlog->setStatus("0");
            $anymarketlog->setStores(array($storeID));
            $anymarketlog->save();

            $anymarketproducts = Mage::getModel('db1_anymarket/anymarketproducts')->setStoreId($storeID)->load($idAnymarket, 'nmp_id');
            if ($anymarketproducts->getNmpId() == null) {
                $anymarketproducts = Mage::getModel('db1_anymarket/anymarketproducts')->setStoreId($storeID)->load($product->getId(), 'nmp_id');
            }

            $anymarketproducts->setNmpId($product->getId());
            $anymarketproducts->setNmpSku($product->getData('sku'));
            $anymarketproducts->setNmpName($product->getData('name'));
            $anymarketproducts->setNmpDescError("");
            $anymarketproducts->setNmpStatusInt("Integrado");
            $anymarketproducts->setStatus("1");
            $anymarketproducts->setStores(array($storeID));
            $anymarketproducts->save();
        }
    }

    /**
     * @param $listTransmissions
     * @param $headers
     * @param $HOST
     * @param $storeID
     *
     * @return string
     */
    public function getSpecificFeedProduct($storeID, $listTransmissions, $headers, $HOST){
        $arrJSONProds = array();
        $arrControlProds = array();

        Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
        foreach ($listTransmissions as $transmissionIDs) {
            $transmissionID = $transmissionIDs->id;
            $transmissionToken = $transmissionIDs->token;

            $transmissionReturn = $this->CallAPICurl("GET", $HOST."/v2/transmissions/".$transmissionID, $headers, null);
            $prodRet = "";
            $typeSincProd = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_prod_sync_field', $storeID);
            if($typeSincProd == 1) {
                if ($transmissionReturn['error'] == '1') {
                    $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                    $anymarketlog->setLogDesc(Mage::helper('db1_anymarket')->__('Error on get transmissions '));
                    $anymarketlog->setStatus("0");
                    $anymarketlog->save();
                } else {
                    $transmission = $transmissionReturn['return'];
                    $statusTransmission = $transmission->publicationStatus;
                    $IDProdTrans = $transmission->product->id;
                    if ($statusTransmission == 'ACTIVE') {
                        $arrVarSku = array();
                        if (isset($transmission->sku->variations)) {
                            foreach ($transmission->sku->variations as $variation) {
                                array_push($arrVarSku, array(
                                    "id" => $variation->id,
                                    "description" => $variation->description,
                                    "variationTypeId" => $variation->type->id,
                                    "variationTypeName" => $variation->type->name
                                ));

                            }
                        }

                        $imagesGallery = array();
                        if( isset($transmission->images) ) {
                            foreach ($transmission->images as $image) {
                                $imagesGallery[] = array(
                                    "standard_resolution" => $image->standardUrl,
                                    "original" => $image->standardUrl,
                                    "main" => $image->main,
                                    "variationValue" => isset($image->variation) ? $image->variation : null
                                );
                            }
                        }

                        $arrVarGeral = array();
                        if (isset($transmission->sku->variations)) {
                            foreach ($transmission->sku->variations as $varSKU) {
                                array_push($arrVarGeral, array(
                                    "name" => $varSKU->type->name,
                                    "id" => $varSKU->type->id
                                ));
                            }
                        }

                        if (!in_array($IDProdTrans, $arrControlProds)) {
                            array_push($arrControlProds, $IDProdTrans);

                            $arrAttr = array();
                            if (isset($transmission->characteristics)) {
                                foreach ($transmission->characteristics as $carac) {
                                    $attrMG = Mage::getModel('eav/entity_attribute')->getCollection()
                                        ->addFieldToFilter('frontend_label', $carac->name);

                                    if ($attrMG->getSize() > 0) {
                                        array_push($arrAttr, array(
                                            "name" => $attrMG->getFirstItem()->getData('attribute_code'),
                                            "value" => $carac->value
                                        ));
                                    }
                                }
                            }

                            $arrJSONProds[$IDProdTrans] = array(
                                "id" => $IDProdTrans,
                                "title" => $transmission->product->title,
                                "idTransmission" => $transmissionIDs->id,
                                "description" => isset($transmission->description) ? $transmission->description : null,
                                "brand" => isset($transmission->brand->name) ? $transmission->brand->name : null,
                                "model" => isset($transmission->model) ? $transmission->model : null,
                                "videoURL" => isset($transmission->videoUrl) ? $transmission->videoUrl : null,
                                "warrantyTime" => isset($transmission->warrantyTime) ? $transmission->warrantyTime : null,
                                "warranty" => isset($transmission->warrantyText) ? $transmission->warrantyText : null,
                                "height" => isset($transmission->height) ? $transmission->height : null,
                                "width" => isset($transmission->width) ? $transmission->width : null,
                                "weight" => isset($transmission->weight) ? $transmission->weight : null,
                                "length" => isset($transmission->length) ? $transmission->length : null,
                                "originCode" => isset($transmission->origin->id) ? $transmission->origin->id : null,
                                "nbm" => isset($transmission->nbm) ? $transmission->nbm->id : null,
                                "category" => isset($transmission->category->id) ? $transmission->category->id : null,
                                "photos" => $imagesGallery,
                                "variations" => $arrVarGeral,
                                "attributes" => $arrAttr,
                                "skus" => array(
                                    array(
                                        "id" => $transmission->sku->id,
                                        "title" => empty($arrVarGeral) ? $transmission->product->title : $transmission->sku->title,
                                        "idProduct" => $IDProdTrans,
                                        "idInClient" => isset($transmission->sku->idInClient) ? $transmission->sku->idInClient : $transmission->sku->partnerId,
                                        "price" => $transmission->sku->price,
                                        "specialPrice" => ($transmission->sku->price != $transmission->sku->discountPrice) ? $transmission->sku->discountPrice : null,
                                        "stockAmount"  => $transmission->sku->amount,
                                        "ean" => isset($transmission->sku->ean) ? $transmission->sku->ean : null,
                                        "variations" => $arrVarSku
                                    )
                                ),
                            );
                        } else {
                            foreach ($arrVarGeral as $variationGeral) {
                                if (!in_array($variationGeral, $arrJSONProds[$IDProdTrans]['variations'])) {
                                    array_push($arrJSONProds[$IDProdTrans]['variations'], $variationGeral);
                                }
                            }

                            foreach ($imagesGallery as $imageG) {
                                if (!in_array($imageG, $arrJSONProds[$IDProdTrans]['photos'])) {
                                    array_push($arrJSONProds[$IDProdTrans]['photos'], $imageG);
                                }
                            }
                            array_push($arrJSONProds[$IDProdTrans]['skus'], array(
                                "id" => $transmission->sku->id,
                                "title" => $transmission->sku->title,
                                "idProduct" => $IDProdTrans,
                                "idInClient" => isset($transmission->sku->idInClient) ? $transmission->sku->idInClient : $transmission->sku->partnerId,
                                "price" => $transmission->sku->price,
                                "specialPrice" => ($transmission->sku->price != $transmission->sku->discountPrice) ? $transmission->sku->discountPrice : null,
                                "stockAmount" => $transmission->sku->amount,
                                "ean" => isset($transmission->sku->ean) ? $transmission->sku->ean : null,
                                "variations" => $arrVarSku
                            ));
                        }
                    } else if ($statusTransmission == 'PAUSED') {
                        $prodLoaded = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', isset($transmission->sku->partnerId) ? $transmission->sku->partnerId : $IDProdTrans);
                        if ($prodLoaded != null) {
                            if ($prodLoaded->getData('integra_anymarket') == 1) {
                                $prodLoaded->setStatus(2);
                                $prodLoaded->save();

                                $this->changeStatusTransmission($HOST, $headers, $transmissionID, 'Pausado', $transmissionToken);
                                $prodRet = 'Transmission Paused - '.$prodLoaded->getSku();
                            }
                        }
                    } else if ($statusTransmission == 'CLOSED') {
                        $prodLoaded = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', isset($transmission->sku->partnerId) ? $transmission->sku->partnerId : $IDProdTrans);
                        if ($prodLoaded != null) {
                            if ($prodLoaded->getData('integra_anymarket') == 1) {
                                $prodLoaded->setStatus(2);
                                $prodLoaded->save();

                                $this->changeStatusTransmission($HOST, $headers, $transmissionID, 'Finalizado', $transmissionToken);
                                $prodRet = 'Transmission Closed - '.$prodLoaded->getSku();
                            }
                        }
                    } elseif ($statusTransmission == 'WITHOUT_STOCK') {
                        $prodLoaded = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', isset($transmission->sku->partnerId) ? $transmission->sku->partnerId : $IDProdTrans);
                        if ($prodLoaded != null) {
                            if ($prodLoaded->getData('integra_anymarket') == 1) {

                                // DECREMENTE O STOCK
                                $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($prodLoaded->getId());
                                if ($stockItem->getManageStock()) {
                                    //$prodLoaded->setStatus(2);
                                    $stockItem->setData('qty', 0);
                                    $stockItem->setData('is_in_stock', 0);
                                    $stockItem->save();
                                }
                                $prodLoaded->save();

                                $prodRet = 'Product Without Stock - '.$prodLoaded->getSku();
                                $this->changeStatusTransmission($HOST, $headers, $transmissionID, 'Sem Estoque', $transmissionToken);
                            }
                        }
                    }
                }
            }

            $typeSincOrder = Mage::getStoreConfig('anymarket_section/anymarket_integration_order_group/anymarket_type_order_sync_field', $storeID);
            if( $typeSincOrder == 1 ){
                if( $transmissionReturn['error'] == '0' ) {
                    $transmissionStock = $transmissionReturn['return'];
                    $skuToLoad = isset($transmissionStock->sku->partnerId) ? $transmissionStock->sku->partnerId : $transmissionStock->product->id;
                    $prodLoaded = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', $skuToLoad);
                    if ($prodLoaded != null) {
                        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($prodLoaded->getId());
                        if( ($stockItem->getManageStock()) && ($stockItem->getData('qty') != $transmissionStock->sku->amount) ) {
                            $stockItem->setData('qty', $transmissionStock->sku->amount);
                            if ($transmissionStock->sku->amount > 0) {
                                $stockItem->setData('is_in_stock', 1);
                            } else {
                                $stockItem->setData('is_in_stock', 0);
                            }
                            $stockItem->save();

                            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                            $anymarketlog->setLogDesc( "Stock Updated: ".$transmissionStock->sku->amount );
                            $anymarketlog->setLogId( $prodLoaded->getSku() );
                            $anymarketlog->setStatus("0");
                            $anymarketlog->setStores(array($storeID));
                            $anymarketlog->save();
                        }else{
                            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                            $anymarketlog->setLogDesc( "Estoques iguais." );
                            $anymarketlog->setLogId( $prodLoaded->getSku() );
                            $anymarketlog->setStatus("0");
                            $anymarketlog->setStores(array($storeID));
                            $anymarketlog->save();
                        }

                        $prodRet = $prodLoaded->getSku()." - Stock Updated";
                    }else{
                        $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                        $anymarketlog->setLogDesc( "Product not found (".$skuToLoad.") - Stock Updated" );
                        $anymarketlog->setStatus("0");
                        $anymarketlog->setStores(array($storeID));
                        $anymarketlog->save();

                        $prodRet = "Product not found (".$skuToLoad.") - Stock Updated";
                    }
                }else{
                    $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                    $anymarketlog->setLogDesc( "Error on Update Stock from transmission" );
                    $anymarketlog->setStatus("0");
                    $anymarketlog->setStores(array($storeID));
                    $anymarketlog->save();

                    $prodRet = "Error on Update Stock";
                }
            }
        }

        //CRIA OS PRODUTOS CASO ELES NAO EXISTAM
        foreach ($arrJSONProds as $ProdsJSON) {
            $feedReturn = $this->createProducts($storeID, json_encode($ProdsJSON));
            if($feedReturn){
                $this->changeStatusTransmission($HOST, $headers, $ProdsJSON["idTransmission"], 'Ativo', $transmissionToken);

                $returnProd = array();
                $returnProd['return'] = 'Product Created or updated.';
                $returnProd['json'] = '';
                $returnProd['error'] = '0';
                $this->saveLogsProds($storeID, "1", $returnProd, $feedReturn);

                $prodRet = 'Product Created or updated.';
            }
        }
        $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
        $anymarketlog->setLogDesc($prodRet);
        $anymarketlog->setStatus("1");
        $anymarketlog->setStores(array($storeID));
        $anymarketlog->save();

        return $prodRet;
    }

    private function prepareToSaveSimpleProductConfigurable($storeID, $ProdsJSON, $sku, $variationArray){
        $imagesGallery = array();
        $AttributeIds = array();
        $objVariations = array();
        foreach ($sku->variations as  $varValues) {
            $descVar = $varValues->description;
            $idVar = $varValues->variationTypeId;

            $AttributeId = Mage::getModel('eav/entity_attribute')->getIdByCode('catalog_product', $variationArray[ $idVar ] );
            if (!in_array($AttributeId, $AttributeIds)) {
                $AttributeIds[] = $AttributeId;
                $collectionAttr = Mage::getResourceModel('eav/entity_attribute_option_collection')
                    ->setPositionOrder('asc')
                    ->setAttributeFilter($AttributeId)
                    ->setStoreFilter(0)
                    ->load();

                $AttributeOptions[$idVar] = $collectionAttr->toOptionArray();

            }

            $varAttr = '';
            $descVarAttr = '';
            foreach ( $AttributeOptions[$idVar] as $attrOpt) {
                if($attrOpt['label'] == $descVar ){
                    $varAttr = $attrOpt['value'];
                    $descVarAttr = $attrOpt['label'];
                    break;
                }
            }

            if($varAttr != ''){
                foreach ($ProdsJSON->photos as $image) {
                    if( $image->variationValue != null ){
                        if( $image->variationValue == $descVarAttr ){
                            $imagesGallery[] = array('img' => $image->standard_resolution, 'main' => $image->main);
                        }
                    }
                }

                $objVariations[ $variationArray[ $idVar ] ] = $varAttr;
            }else{
                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( 'Opção de variação sem correspondente no magento ('.$varValues->variationTypeName.') - '.$descVar );
                $anymarketlog->setStatus("0");
                $anymarketlog->setStores(array($storeID));
                $anymarketlog->save();

                return null;
            }


        }

        return array( "idsVariations" => $AttributeIds,
            "variations" => $objVariations,
            "images" => $imagesGallery
        );
    }


    /**
     * @param $ProdsJSON
     * @param $storeID
     * @return Mage_Catalog_Model_Product
     */
    public function createProducts($storeID, $ProdsJSON){
        Mage::getSingleton('core/session')->setImportProdsVariable('false');
        $ProdsJSON = json_decode($ProdsJSON);

        $ProdCrt = null;
        $websiteID = Mage::getModel('core/store')->load($storeID)->getWebsiteId();
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

        $typeSincOrder = Mage::getStoreConfig('anymarket_section/anymarket_integration_order_group/anymarket_type_order_sync_field', $storeID);

        $priceField = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
        if($priceField == 'final_price'){
            $priceField = 'price';

            $config = new Mage_Core_Model_Config();
            $config->saveConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', 'price', 'default', $storeID);

            Mage::app()->getCacheInstance()->cleanType('config');
        }

        $specialToDate = date('Y-m-dTH:i:sZ', strtotime('+5 years'));
        $specialFromDate = date('Y-m-dTH:i:sZ', strtotime('-1 years'));

        $brand =    Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_brand_field', $storeID);
        $nbm   =    Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_nbm_field', $storeID);
        $model =    Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_model_field', $storeID);
        $MassUnit = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_weight_field', $storeID);
        $UnitMeasurement = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_size_field', $storeID);
        $AttrSet  = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_atribute_set_field', $storeID);

        $volume_comprimento = Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_vol_comp_field', $storeID);
        $volume_altura =      Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_vol_alt_field', $storeID);
        $volume_largura =     Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_vol_larg_field', $storeID);
        $video_url =          Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_video_url_field', $storeID);
        $nbm_origin =         Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_nbm_origin_field', $storeID);
        $ean =                Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_ean_field', $storeID);
        $warranty_text =      Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_warranty_text_field', $storeID);
        $warranty_time =      Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_warranty_time_field', $storeID);

        $configureFieldsConfig = $this->getFieldsDescriptionConfig($storeID);

        //PROD CONFIGURABLE
        if ( !empty( $ProdsJSON->variations ) ) {
            $prodSimpleFromConfig = array();
            $AttributeIds = array();

            $variationArray = array();
            $sinc = '';
            foreach ($ProdsJSON->variations as $variation) {
                $AttrCtlr = Mage::getModel('eav/entity_attribute')->getCollection()
                    ->addFieldToFilter('frontend_label', $variation->name);
                $attrConfig = $AttrCtlr->getFirstItem();

                if(!$attrConfig->getData()){
                    $sinc = $variation->name;
                    break;
                }
                $variationArray[$variation->id] = $attrConfig->getData('attribute_code');
            }

            if($sinc == ''){
                foreach ($ProdsJSON->skus as  $sku) {
                    $IDSKUProd = $sku->idInClient != null ? $sku->idInClient : $sku->id;
                    $ProdCrt = '';
                    $product = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', $IDSKUProd);

                    foreach ($sku->variations as  $varValues) {
                        $idVar = $varValues->variationTypeId;
                    }

                    //trata as dimensoes
                    $vHeight = $this->procAttrConfig($volume_altura, $ProdsJSON->height, 0);
                    $vWidth = $this->procAttrConfig($volume_largura, $ProdsJSON->width, 0);
                    $vLength = $this->procAttrConfig($volume_comprimento, $ProdsJSON->length, 0);

                    if(!$product){
                        $preparedProduct = $this->prepareToSaveSimpleProductConfigurable($storeID, $ProdsJSON, $sku, $variationArray);
                        if( $preparedProduct == null ) {
                            return false;
                        }

                        $AttributeIds = $preparedProduct['idsVariations'];
                        $dataPrd = array(
                            'attribute_set_id' => $AttrSet == null ? Mage::getModel('catalog/product')->getDefaultAttributeSetId() : $AttrSet,
                            'type_id' => 'simple',
                            'sku' => $IDSKUProd,
                            'name' => $sku->title,
                            'description' => $sku->title,
                            'short_description' => $sku->title,
                            $priceField => $sku->price,
                            'special_price' => $sku->specialPrice,
                            'special_to_date'   => $sku->specialPrice != null ? $specialToDate   : null,
                            'special_from_date' => $sku->specialPrice != null ? $specialFromDate : null,
                            'created_at' => strtotime('now'),
                            'updated_at' => strtotime('now'),
                            'weight' => $MassUnit == 1 ? $ProdsJSON->weight * 1000 : $ProdsJSON->weight,
                            'store_id' => $storeID,
                            'website_ids' => array($websiteID),
                            $brand => $this->procAttrConfig($brand, $ProdsJSON->brand, 0),
                            $model => $this->procAttrConfig($model, $ProdsJSON->model, 0),
                            $video_url => $this->procAttrConfig($video_url, $ProdsJSON->videoURL, 0),

                            $volume_comprimento => $this->convertUnitMeasurement($UnitMeasurement, $vLength, 0),
                            $volume_altura => $this->convertUnitMeasurement($UnitMeasurement, $vHeight, 0),
                            $volume_largura => $this->convertUnitMeasurement($UnitMeasurement, $vWidth, 0),

                            $warranty_time => $this->procAttrConfig($warranty_time, $ProdsJSON->warrantyTime, 0),
                            $nbm => $this->procAttrConfig($nbm, $ProdsJSON->nbm, 0),
                            $nbm_origin => $this->procAttrConfig($nbm_origin, $ProdsJSON->originCode, 0),
                            $ean => $this->procAttrConfig($ean, $sku->ean, 0),
                            $warranty_text => $this->procAttrConfig($warranty_text, $ProdsJSON->warranty, 0),
                            'msrp_enabled' => '2',
                            'categoria_anymarket' => $ProdsJSON->category
                        );

                        //adiciona no produto as variacoes
                        foreach ($preparedProduct['variations'] as $varKey => $varObg) {
                            $dataPrd[$varKey] = $varObg;
                        }

                        foreach ($ProdsJSON->attributes as $attrProd) {
                            $dataPrd[strtolower($attrProd->name)] = $this->procAttrConfig(strtolower($attrProd->name), $attrProd->value, 0);
                        }

                        $dataPrdSimple = array(
                            'product' => $dataPrd,
                            'stock_item' => array(
                                'is_in_stock' => $sku->stockAmount > 0 ? '1' : '0',
                                'qty' => $sku->stockAmount,
                            ),
                            'images' => $preparedProduct['images'],
                        );

                        $ProdReturn = $this->create_simple_product($storeID, $dataPrdSimple);
                        $ProdCrt = $ProdReturn->getEntityId();

                        $product = Mage::getModel('catalog/product')->load($ProdCrt);
                    }else{
                        $product->setUrlKey(false);

                        //Atualiza Imagens
                        $this->update_image_product($product, $ProdsJSON, $IDSKUProd);

                        $webSiteIds = $product->getWebsiteIds();
                        if(!in_array($websiteID, $webSiteIds)){
                            array_push($webSiteIds, $websiteID);
                            $product->setWebsiteIds( $webSiteIds );
                        }

                        $product->setStoreId($storeID);
                        $product->setName( $sku->title );
                        $product->setDescription( $sku->title );
                        $product->setShortDescription( $sku->title );
                        $product->setData('weight', $MassUnit == 1 ? $ProdsJSON->weight*1000 : $ProdsJSON->weight);

                        $product->setData($priceField, $sku->price);
                        $product->setData('special_price', $sku->specialPrice);
                        $product->setData('special_to_date', $sku->specialPrice != null ? $specialToDate : null);
                        $product->setData('special_from_date', $sku->specialPrice != null ? $specialFromDate : null);
                        $product->setData($brand, $this->procAttrConfig($brand, $ProdsJSON->brand, 0));
                        $product->setData($model, $this->procAttrConfig($model, $ProdsJSON->model, 0));
                        $product->setData($video_url, $this->procAttrConfig($video_url, $ProdsJSON->videoURL, 0));

                        $product->setData($volume_comprimento, $this->convertUnitMeasurement($UnitMeasurement, $vLength, 0));
                        $product->setData($volume_altura, $this->convertUnitMeasurement($UnitMeasurement, $vHeight, 0));
                        $product->setData($volume_largura,$this->convertUnitMeasurement($UnitMeasurement, $vWidth, 0));

                        $product->setData($warranty_time, $this->procAttrConfig($warranty_time, $ProdsJSON->warrantyTime, 0));
                        $product->setData($nbm, $this->procAttrConfig($nbm, $ProdsJSON->nbm, 0));
                        $product->setData($nbm_origin, $this->procAttrConfig($nbm_origin, $ProdsJSON->originCode, 0));
                        $product->setData($ean, $this->procAttrConfig($ean, $sku->ean, 0));
                        $product->setData($warranty_text, $this->procAttrConfig($warranty_text, $ProdsJSON->warranty, 0));
                        $product->setData('categoria_anymarket', $ProdsJSON->category);
                        $product->setData('name', $sku->title);
                        $product->setStatus(1);
                        $product->save();

                        foreach ($ProdsJSON->attributes as  $attrProd) {
                            $product->setData( strtolower($attrProd->name), $this->procAttrConfig(strtolower($attrProd->name), $attrProd->value, 0));
                        }

                        if( $typeSincOrder == 0 ) {
                            $qtyStock = $sku->stockAmount;
                            $inStock = $qtyStock > 0 ? '1' : '0';

                            $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
                            $stockItem->setData('is_in_stock', $inStock);
                            $stockItem->setData('qty', $qtyStock);
                            $stockItem->save();
                        }

                        $ProdCrt = $product->getId();
                    }

                    $returnProd['return'] = Mage::helper('db1_anymarket')->__('Simple product Created').' ('.$ProdCrt.')';
                    $returnProd['error'] = '0';
                    $returnProd['json'] = '';

                    $this->saveLogsProds($storeID, "1", $returnProd, $product);

                    if($ProdCrt != ''){
                        $prodSimpleFromConfig[] = array('AttributeText' => $variationArray[ $idVar ], 'Id' => $ProdCrt);
                        $ProdCrt = '';
                    }
                }

                $collectionConfigurable = Mage::getResourceModel('catalog/product_collection')
                    ->addAttributeToFilter('type_id', array('eq' => 'configurable'));

                $prod = null;
                foreach ($collectionConfigurable as $prodConfig) {
                    $prodTmp = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $prodConfig->getId() );
                    if( $prodTmp->getData('id_anymarket') == $ProdsJSON->id ){
                        $prod = $prodTmp;
                        break;
                    }

                }

                $imagesGallery = array();
                foreach ($ProdsJSON->photos as $image) {
                    $imagesGallery[] = array('img' => $image->standard_resolution, 'main' => $image->main);
                }

                if( $prod == null ){
                    if($prodSimpleFromConfig){
                        $dataProdConfig = array(
                            'stock' => '0',
                            'price' => '0',
                            'name' => $ProdsJSON->title,
                            'brand' => '',
                            'sku' => $ProdsJSON->id,
                            'categoria_anymarket' => $ProdsJSON->category,
                            'images' => $imagesGallery
                        );

                        foreach ($configureFieldsConfig as $fieldConfig) {
                            $dataProdConfig[$fieldConfig] = $ProdsJSON->description;
                        }

                        $ProdCrt = $this->create_configurable_product($storeID, $dataProdConfig, $prodSimpleFromConfig, $AttributeIds);
                    }
                }else{
                    $dataProdConfig = array(
                        'stock' => '0',
                        'price' => '0',
                        'name' => $ProdsJSON->title,
                        'brand' => '',
                        'sku' => $ProdsJSON->id,
                        'categoria_anymarket' => $ProdsJSON->category
                    );

                    foreach ($configureFieldsConfig as $fieldConfig) {
                        $dataProdConfig[$fieldConfig] = $ProdsJSON->description;
                    }

                    $this->update_configurable_product($storeID, $prod->getId(), $dataProdConfig, $prodSimpleFromConfig);
                }
            }else{
                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( 'Variação sem correspondente no magento ('.utf8_decode($sinc).') - Produto: '.$ProdsJSON->id);
                $anymarketlog->setStatus("0");
                $anymarketlog->setStores(array($storeID));
                $anymarketlog->save();
            }
        }else{
            // PROD SIMPLES
            foreach ($ProdsJSON->skus as $ProdJSON) {
                $skuProd = $ProdJSON;
            }

            $imagesGallery = array();
            foreach ($ProdsJSON->photos as $image) {
                $imagesGallery[] = array('img' => $image->standard_resolution, 'main' => $image->main);
            }

            $IDSkuJsonProd = $skuProd->idInClient != null ? $skuProd->idInClient : $skuProd->idProduct;
            $product = Mage::getModel('catalog/product')->setStoreId($storeID)->loadByAttribute('sku', $IDSkuJsonProd);
            foreach ($ProdsJSON->skus as  $sku) {
                $skuEan = $sku->ean;
            }

            //trata as dimensoes
            $vHeight = $this->procAttrConfig($volume_altura, $ProdsJSON->height, 0);
            $vWidth  = $this->procAttrConfig($volume_largura, $ProdsJSON->width, 0);
            $vLength = $this->procAttrConfig($volume_comprimento, $ProdsJSON->length, 0);

            if(!$product){
                $dataPrd = array(
                    'attribute_set_id' => $AttrSet == null ? Mage::getModel('catalog/product')->getDefaultAttributeSetId() : $AttrSet,
                    'type_id' =>  'simple',
                    'sku' => $IDSkuJsonProd,
                    'name' => $skuProd->title,
                    $priceField => $skuProd->price,
                    'special_price' => $skuProd->specialPrice,
                    'special_to_date'   => $skuProd->specialPrice != null ? $specialToDate   : null,
                    'special_from_date' => $skuProd->specialPrice != null ? $specialFromDate : null,
                    'created_at' => strtotime('now'),
                    'updated_at' => strtotime('now'),
                    'weight' => $MassUnit == 1 ? $ProdsJSON->weight*1000 : $ProdsJSON->weight,
                    'store_id' => $storeID,
                    'website_ids' => array($websiteID),
                    $brand => $this->procAttrConfig($brand, $ProdsJSON->brand, 0),
                    $nbm => $this->procAttrConfig($nbm, $ProdsJSON->nbm, 0),
                    $model => $this->procAttrConfig($model, $ProdsJSON->model, 0),
                    $video_url => $this->procAttrConfig($video_url, $ProdsJSON->videoURL, 0),

                    $volume_comprimento => $this->convertUnitMeasurement($UnitMeasurement, $vLength, 0),
                    $volume_altura  => $this->convertUnitMeasurement($UnitMeasurement, $vHeight, 0),
                    $volume_largura => $this->convertUnitMeasurement($UnitMeasurement, $vWidth, 0),

                    $warranty_time => $this->procAttrConfig($warranty_time, $ProdsJSON->warrantyTime, 0),
                    $nbm_origin => $this->procAttrConfig($nbm_origin, $ProdsJSON->originCode, 0),
                    $ean => $this->procAttrConfig($ean, $skuEan, 0),
                    $warranty_text => $this->procAttrConfig($warranty_text, $ProdsJSON->warranty, 0),
                    'msrp_enabled' =>  '2',
                    'categoria_anymarket' => $ProdsJSON->category,
                );

                foreach ($configureFieldsConfig as $fieldConfig) {
                    $dataPrd[$fieldConfig] = $ProdsJSON->description;
                }

                foreach ($ProdsJSON->attributes as  $attrProd) {
                    $dataPrd[ strtolower($attrProd->name) ] = $this->procAttrConfig(strtolower($attrProd->name), $attrProd->value, 0);
                }

                $data = array(
                    'product' => $dataPrd,
                    'stock_item' => array(
                        'is_in_stock' =>  $skuProd->stockAmount > 0 ? '1' : '0',
                        'qty' => $skuProd->stockAmount,
                    ),
                    'images' => $imagesGallery,

                );

                $ProdCrt = $this->create_simple_product($storeID, $data);
            }else{
                $product->setUrlKey(false);

                //Atualiza Imagens
                $this->update_image_product($product, $ProdsJSON, $IDSkuJsonProd);

                $webSiteIds = $product->getWebsiteIds();
                if(!in_array($websiteID, $webSiteIds)){
                    array_push($webSiteIds, $websiteID);
                    $product->setWebsiteIds( $webSiteIds );
                }

                $product->setStoreId($storeID);
                $product->setName( $skuProd->title );

                foreach ($configureFieldsConfig as $fieldConfig) {
                    $product->setData($fieldConfig, $ProdsJSON->description);
                }

                $product->setData('weight', $MassUnit == 1 ? $ProdsJSON->weight*1000 : $ProdsJSON->weight);

                $product->setData($brand, $this->procAttrConfig($brand, $ProdsJSON->brand, 0));
                $product->setData($model, $this->procAttrConfig($model, $ProdsJSON->model, 0));
                $product->setData($video_url, $this->procAttrConfig($video_url, $ProdsJSON->videoURL, 0));

                $product->setData($volume_comprimento, $this->convertUnitMeasurement($UnitMeasurement, $vLength, 0));
                $product->setData($volume_altura,  $this->convertUnitMeasurement($UnitMeasurement, $vHeight, 0));
                $product->setData($volume_largura, $this->convertUnitMeasurement($UnitMeasurement, $vWidth, 0));

                $product->setData($warranty_time, $this->procAttrConfig($warranty_time, $ProdsJSON->warrantyTime, 0));
                $product->setData($nbm, $this->procAttrConfig($nbm, $ProdsJSON->nbm, 0));
                $product->setData($nbm_origin, $this->procAttrConfig($nbm_origin, $ProdsJSON->originCode, 0));
                $product->setData($ean, $this->procAttrConfig($ean, $skuEan, 0));
                $product->setData($warranty_text, $this->procAttrConfig($warranty_text, $ProdsJSON->warranty, 0));
                $product->setData('categoria_anymarket', $ProdsJSON->category);
                $product->setData('name', $ProdsJSON->title);
                $product->setStatus(1);

                foreach ($ProdsJSON->attributes as  $attrProd) {
                    $product->setData( strtolower($attrProd->name), $this->procAttrConfig(strtolower($attrProd->name), $attrProd->value, 0));
                }

                $product->setData($priceField, $skuProd->price);
                $product->setData('special_price', $skuProd->specialPrice);
                $product->setData('special_to_date',   $skuProd->specialPrice != null ? $specialToDate   : null);
                $product->setData('special_from_date', $skuProd->specialPrice != null ? $specialFromDate : null);

                if( $typeSincOrder == 0 ) {
                    $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
                    $stockItem->setData('is_in_stock', $ProdsJSON->skus[0]->stockAmount > 0 ? '1' : '0');
                    $stockItem->setData('qty', $ProdsJSON->skus[0]->stockAmount);
                    $stockItem->save();
                }

                $product->save();

                $ProdCrt = $product;
            }

        }
        Mage::getSingleton('core/session')->setImportProdsVariable('true');
        return $ProdCrt;
    }

    public function listAllProds($storeID){
        try {
            $typeSincProd = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_prod_sync_field', $storeID);
            if($typeSincProd == 0){
                $products = $products = Mage::getModel('catalog/product')
                    ->getCollection()
                    ->addAttributeToSelect('name');
                $cont = 0;
                foreach($products as $product) {
                    $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild( $product->getId() );

                    if (!$parentIds) {
                        $anymarketproductsUpdt =  Mage::getModel('db1_anymarket/anymarketproducts')->setStoreId($storeID)->load($product->getId(), 'nmp_id');
                        if(is_array($anymarketproductsUpdt->getData('store_id'))){
                            $arrvar = array_values($anymarketproductsUpdt->getData('store_id'));
                            $StoreIDAmProd = array_shift($arrvar);
                        }else{
                            $StoreIDAmProd = $anymarketproductsUpdt->getData('store_id');
                        }
                        if( ($anymarketproductsUpdt->getData('nmp_id') == null) || ($StoreIDAmProd != $storeID) ){

                            $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild( $product->getId() );
                            if (!isset($parentIds[0])) {
                                $name = $product->getName();
                                $sku  = $product->getSku();
                                $IDProd  = $product->getId();

                                $anymarketproducts = Mage::getModel('db1_anymarket/anymarketproducts')->setStoreId($storeID)->load($IDProd ,'nmp_id');
                                $anymarketproducts->setNmpId( $IDProd );
                                $anymarketproducts->setNmpSku( $sku );
                                $anymarketproducts->setNmpName( $name );
                                $anymarketproducts->setNmpDescError("");
                                $anymarketproducts->setNmpStatusInt("Não integrado (Magento)");
                                $anymarketproducts->setStatus($product->getData('integra_anymarket'));
                                $anymarketproducts->setStores(array($storeID));
                                $anymarketproducts->save();

                                $cont = $cont+1;
                            }
                        }else{
                            if( ($anymarketproductsUpdt->getData('nmp_sku') != $product->getSku() ) || ($anymarketproductsUpdt->getData('nmp_name') != $product->getName() ) ){
                                $anymarketproductsUpdt->setNmpSku( $product->getSku() );
                                $anymarketproductsUpdt->setNmpName( $product->getName() );
                                $anymarketproductsUpdt->save();

                                $cont = $cont+1;
                            }
                        }
                    }

                }

                if($cont > 0){
                    Mage::getSingleton('adminhtml/session')->addSuccess(
                        Mage::helper('db1_anymarket')->__('Total %d products successfully listed.', $cont)
                    );
                }
            }
        } catch (Mage_Core_Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError(
                Mage::helper('db1_anymarket')->__('There was an error updating the products.')
            );
            Mage::logException($e);
        }
    }

    public function getDetailsOfBundle($product){
        $selectionCollection = $product->getTypeInstance(true)->getSelectionsCollection(
            $product->getTypeInstance(true)->getOptionsIds($product), $product
        );

        $bundled_items = array();
        foreach($selectionCollection as $option)
        {
            $bundled_items[] = $option->getData();
        }

        return $bundled_items;
    }

    public function getWeightOfBundle($storeID, $product){
        $selectionCollection = $product->getTypeInstance(true)->getSelectionsCollection(
            $product->getTypeInstance(true)->getOptionsIds($product), $product
        );

        $WeightTotal = 0;
        foreach($selectionCollection as $option)
        {
            $prodOfBundle = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $option->getId() );
            $WeightTotal += $option->getData('selection_qty')*$prodOfBundle->getWeight();
        }

        return $WeightTotal;
    }

    public function getDimensionsOfBundle($storeID, $product, $volume_altura, $volume_largura, $volume_comprimento){
        $selectionCollection = $product->getTypeInstance(true)->getSelectionsCollection(
            $product->getTypeInstance(true)->getOptionsIds($product), $product
        );

        $height = 0;
        $width  = 0;
        $length = 0;
        foreach($selectionCollection as $option)
        {
            $prodOfBundle = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $option->getId() );
            if( $prodOfBundle->getData( $volume_comprimento ) > $length ){
                $length = $prodOfBundle->getData( $volume_comprimento );
            }

            if( $prodOfBundle->getData( $volume_largura ) > $width ){
                $width = $prodOfBundle->getData( $volume_largura );
            }

            $height += $prodOfBundle->getData( $volume_altura );
        }

        return array( "length" => $length, "width" => $width, "height" => $height );
    }

    public function getStockPriceOfBundle($product){
        $selectionCollection = $product->getTypeInstance(true)->getSelectionsCollection(
            $product->getTypeInstance(true)->getOptionsIds($product), $product
        );

        $stockAt = null;
        $priceBundleProd = $product->getData('price');
        $priceTot = 0;
        $outStock = false;
        foreach($selectionCollection as $child){
            //GET STOCK
            $requiredStock = $child->getData('selection_qty');
            $realStock = $child->getStockItem()->getData('qty');

            if( $realStock > $requiredStock) {
                $tmpStock = (int)($realStock / $requiredStock);
                if ($stockAt != null) {
                    if ($tmpStock < $stockAt) {
                        $stockAt = $tmpStock;
                    }
                } else {
                    $stockAt = $tmpStock;
                }
            }else{
                $outStock = true;
            }

            //GET PRICE
            $priceType = $child->getData('selection_price_type');
            $priceValue = $child->getData('selection_price_value');

            if($priceValue > 0) {
                //fixed
                if ($priceType == '0') {
                    $priceItem = ($requiredStock * $priceValue);
                } else {
                    $priceValue = ($priceValue / 100) * $priceBundleProd;
                    $priceItem = ($requiredStock * $priceValue);
                }
                $priceTot += $priceItem;
            }

        }
        $retArray = array(
            "price" => $priceTot + $priceBundleProd,
            "stock" => $stockAt == null || $outStock ? 0 : $stockAt
        );

        return $retArray;
    }

    /**
     * @param $idProd
     * @return array
     */
    private function findBundledProductsWithThisChildProduct($idProd)
    {
        $bundles = array();
        $products = Mage::getModel('catalog/product')
            ->getCollection()
            ->addFieldToFilter('type_id','bundle');

        foreach($products as $product){
            $children_ids_by_option = $product
                ->getTypeInstance($product)
                ->getChildrenIds($product->getId(),false);

            $ids = array();
            foreach($children_ids_by_option as $array){
                $ids = array_merge($ids, $array);
            }

            if(in_array($idProd, $ids)){
                $bundles[] = $product;
            }
        }

        return $bundles;
    }

    /**
     * @param $IDProd
     * @param $QtdStock
     * @param $Price
     * @return boolean
     */
    public function updatePriceStockAnyMarket($storeID, $IDProd, $QtdStock, $Price){
        $product = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $IDProd );
        if($product->getTypeID() == "configurable") {
            $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
            $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $product);
            foreach ($childProducts as $child) {
                $this->updatePriceStockAnyMarket($storeID, $child->getId(), $child->getStockItem()->getQty(), $child->getData($filter));
            }

            return false;
        }

        if( $product->getData('integra_anymarket') != 1 ){
            $bundleModel = Mage::getResourceSingleton('bundle/selection');
            if($bundleModel) {
                $bundleIds = $bundleModel->getParentIdsByChild($IDProd);
                if ($bundleIds) {
                    foreach ($bundleIds as $bundle) {
                        $ProdStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($bundle);
                        $this->updatePriceStockAnyMarket($storeID, $bundle, $ProdStock->getQty(), null);
                    }
                }
            }
            return false;
        }

        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $idAnymarket = $this->getIdInAnymarketBySku($storeID, $product);
        if( $idAnymarket == null ) {
            return false;
        }

        $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));

        $headers = array(
            "Content-type: application/json",
            "Accept: */*",
            "gumgaToken: ".$TOKEN
        );

        //TRATAMENTO PARA BUNDLE
        $bundles =  $this->findBundledProductsWithThisChildProduct($IDProd);
        foreach($bundles as $prodBund) {
            $this->updatePriceStockAnyMarket($storeID, $prodBund->getId(), null, null);
        }

        $typeSincProd = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_prod_sync_field', $storeID);
        $typeSincOrder = Mage::getStoreConfig('anymarket_section/anymarket_integration_order_group/anymarket_type_order_sync_field', $storeID);

        if($product->getTypeID() == "bundle") {
            $stockPriceBundle = $this->getStockPriceOfBundle($product);
            //OBTEM O STOCK DO ITEM BUNDLE
            if( $typeSincOrder == 0 ){
                $QtdStock = null;
            }else{
                $QtdStock = $stockPriceBundle["stock"];
            }

            if( $typeSincProd == 0 ){
                $Price = $stockPriceBundle["price"];
            }else{
                $Price = null;
            }

            if($product->getPriceType() == 0 && $Price == null ) {
                $priceModel = $product->getPriceModel();
                $PricesBundle = $priceModel->getTotalPrices($product, null, true, false);
                $Price = reset($PricesBundle);
            }

        }else{
            if( $typeSincProd == 0 ){
                if($filter == 'final_price'){
                    $Price = $product->getFinalPrice();
                }else{
                    $Price = $product->getData($filter);
                }
            }else{
                $Price = null;
            }

            if( $typeSincOrder == 0 ){
                $QtdStock = null;
            }elseif( !is_numeric ( $QtdStock ) ){
                $QtdStock = null;
            }
        }

        if( ($QtdStock != null) || ($Price != null) ){
            $params = array(
                "partnerId" => $product->getSku(),
                "quantity" => $QtdStock,
                "cost" => $Price
            );

            $returnProd = $this->CallAPICurl("PUT", $HOST."/v2/stocks", $headers, array($params));
            if($returnProd['return'] == ''){
                $returnProd['return'] = Mage::helper('db1_anymarket')->__('Update Stock and Price');
                $returnProd['error'] = '0';
                $returnProd['json'] = json_encode($params);
            }

            if( $returnProd['error'] == '1' ){
                $this->saveLogsProds($storeID, "0", $returnProd, $product);
            }else{
                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc(  Mage::helper('db1_anymarket')->__('Update stock and price.') );
                $anymarketlog->setStatus("0");
                $anymarketlog->setLogId( $product->getId() );
                $anymarketlog->setLogJson( json_encode($params) );
                $anymarketlog->setStores(array($storeID));
                $anymarketlog->save();
            }
        }
    }

}