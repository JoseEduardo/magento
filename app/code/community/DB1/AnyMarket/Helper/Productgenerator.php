<?php

class DB1_AnyMarket_Helper_ProductGenerator extends DB1_AnyMarket_Helper_Data
{
    protected $_defaultData = array(
        'product' => array(
            'attribute_set_id' => '4',
            'type_id' =>  'simple',
            'sku' =>  '0124ASF3',
            'has_options' =>  '0',
            'required_options' =>  '0',
            'created_at' =>  '',
            'updated_at' =>  '',
            'status' =>  '1',
            'visibility' =>  '4',
            'volume_comprimento' => null,
            'volume_altura' => null,
            'volume_largura' => null,
            'warranty_time' => null,
            'tax_class_id' =>  '0',
            'is_recurring' =>  '0',
            'weight' =>  '1.0000',
            'price' =>  '0',
            'cost' =>  '0',
            'special_price' => null,
            'msrp' => null,
            'name' =>  'product no name' ,
            'url_key' =>  '',
            'coun1_of_manufacture' => null,
            'video_url' => null,
            'nbm' =>  '1',
            'nbm_origin' =>  '1',
            'ean' =>  '1',
            'warranty_text' => null,
            'msrp_enabled' =>  '2',
            'msrp_display_actual_price_type' =>  '4',
            'meta_title' => null,
            'meta_description' => null,
            'image' =>  'no_selection',
            'small_image' =>  'no_selection',
            'thumbnail' =>  'no_selection',
            'custom_design' => null,
            'page_layout' => null,
            'options_container' =>  'container1',
            'gift_message_available' => null,
            'url_path' =>  'teste.html',
            'news_from_date' => null,
            'news_to_date' => null,
            'special_from_date' => null,
            'special_to_date' => null,
            'custom_design_from' => null,
            'custom_design_to' => null,
            'description' =>  '',
            'short_description' =>  '.',
            'meta_keyword' => null,
            'custom_layout_update' => null,
            'is_salable' =>  '1',
            'integra_anymarket' => '1',
            'categoria_anymarket' => '',
            'category_ids' => array(2,3,4),
        ),
        'images' => array(),
        'stock_item' => array(
            'use_config_manage_stock' => '1',
            'inventory_manage_stock' => '1',
            'manage_stock' => '1',
            'min_sale_qty' => '1',
            'max_sale_qty' => '',
            'is_in_stock' => '1',
            'qty' => '9999',
        ),
    );

    /**
     * update images in MG
     *
     * @param $product
     * @param $data
     */
    public function updateImages($product, $data){
        $sku = $data['sku'];
        foreach ($data['images'] as $image) {
            $this->importImages($product, $image, $sku);
        }
    }


    /**
     * create simple prod in MG
     *
     * @param array $data
     * @return Mage_Catalog_Model_Product
     */
    public function createSimpleProduct($data = array()){
        $data = array_replace_recursive($this->_defaultData, $data);

        $product = Mage::getModel('catalog/product');
        $product->setData($data['product']);
        $product->setStockData($data['stock_item']);
        $product->setForceConfirmed(true);
        $prodSaved = $product->save();

        $sku = $data['product']['sku'];

        if( array_key_exists("images", $data) ){
            foreach ($data['images'] as $image) {
                $this->importImages($prodSaved, $image, $sku);
            }
        }

        return $prodSaved;
    }

    /**
     * import Images
     *
     * @param $product
     * @param $image
     * @param $sku
     */
    private function importImages($product, $image, $sku){
        try{
            $image_url = $image['img'];
            $image_url  = str_replace("https://", "http://", $image_url);
            $image_type = substr(strrchr($image_url,"."),1);
            $split =  explode("?", $image_type);
            $image_type = $split[0];
            $split =  explode("/", $image_type);
            $image_type = $split[0];

            $image_url  = substr($image_url, 0,strpos($image_url, $image_type)+strlen($image_type));

            $imgName = basename($image_url);
            $imgName = str_replace('.'.$image_type, "", $imgName);
            $filename  = md5($imgName . $sku).'.'.$image_type;

            $dirPath = Mage::getBaseDir('media') . DS . 'import';
            if (!file_exists($dirPath)) {
                mkdir($dirPath, 0777, true);
            }

            $filepath   = $dirPath . DS . $filename;

            $curl_handle = curl_init();
            curl_setopt($curl_handle, CURLOPT_URL,$image_url);
            curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 0);
            curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl_handle, CURLOPT_USERAGENT, 'Cirkel');
            $query = curl_exec($curl_handle);
            $err = curl_error($curl_handle);

            if($err){
                Mage::log('CURL ERROR ON CREATE IMAGE FILE '.$sku.' IMAGE '.$image_url, null, 'anymarket_dlog.log');
                Mage::log($err, null, 'anymarket_dlog.log');
            }

            curl_close($curl_handle);

            file_put_contents($filepath, $query);

            if (file_exists($filepath)) {
                $attrIMG = array();

                if( array_key_exists('main', $image) ){
                    if($image['main'] == true){
                        $attrIMG = array('image', 'thumbnail', 'small_image');
                    }
                }

                Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
                $productMG = Mage::getModel('catalog/product')->loadByAttribute('sku', $product->getSku());
                $productMG->addImageToMediaGallery( $filepath, $attrIMG, false, false);
                $productMG->save();
                Mage::app()->setCurrentStore( $this->getCurrentStoreView() );
            }else{
                Mage::log('ERROR ON CREATE IMAGE FILE '.$sku.' IMAGE '.$image_url, null, 'anymarket_dlog.log');
            }
        } catch (Exception $e) {
            Mage::log('TRY CATCH ERROR ON CREATE IMAGE FILE '.$sku.' IMAGE '.$image_url, null, 'anymarket_dlog.log');
            Mage::log($e, null, 'anymarket_dlog.log');
        }

    }

    private function getConfigurableAttributes($AttributeIds){
        $attrArray = array();
        foreach ($AttributeIds as $attr) {
            $attribute = Mage::getModel('eav/entity_attribute')->load($attr);

            $attrArray[] = array(
                'id'             => null,
                'label'          => $attribute->getData('frontend_label'),
                'use_default'    => $attribute->getData('default_value'),
                'position'       => $attribute->getData('position'),
                'values'         => array(),
                'attribute_id'   => $attribute->getData('attribute_id'),
                'attribute_code' => $attribute->getData('attribute_code'),
                'frontend_label' => $attribute->getData('frontend_label'),
                'store_label'    => $attribute->getData('frontend_label'),
            );
        }

        return $attrArray;
    }

    /**
     * create configurable product in MG
     *
     * @param array $dataProdConfig
     * @param array $simpleProducts
     * @param array $AttributeIds
     * @return Mage_Catalog_Model_Product
     */
    public function createConfigurableProduct($storeID, $dataProdConfig = array() , $simpleProducts = array(), $AttributeIds = array()){
        $confProduct = Mage::getModel('catalog/product')->setSku($dataProdConfig['sku']);
        $confProduct->setTypeId('configurable');
        $confProduct->setWebsiteIds(array(1));
        $confProduct->getTypeInstance()->setUsedProductAttributeIds($AttributeIds);

        $configurableProductsData = array();
        $configurableAttributesData = $this->getConfigurableAttributes($AttributeIds);
        $simpleProductIds = array();
        foreach ($simpleProducts as $simpleProduct) {
            $sProd = Mage::getModel('catalog/product')->load( $simpleProduct['Id'] );

            $AttributeId = Mage::getModel('eav/entity_attribute')->getIdByCode('catalog_product', $simpleProduct['AttributeText'] );
            $simpleProductsData = array(
                'label'         => $sProd->getAttributeText( $simpleProduct['AttributeText'] ),
                'attribute_id'  => $AttributeId,
                'value_index'   => (int) $sProd->getData( $simpleProduct['AttributeText'] ),
                'is_percent'    => 0,
                'pricing_value' => $sProd->getPrice(),
            );

            $configurableProductsData[ $sProd->getId() ] = $simpleProductsData;
            $configurableAttributesData[0]['values'][] = $simpleProductsData;

            array_push($simpleProductIds, $simpleProduct['Id']);
        }
        $confProduct->setCanSaveConfigurableAttributes(true);

        $confProduct->setConfigurableProductsData($configurableProductsData);
        $confProduct->setConfigurableAttributesData($configurableAttributesData);

        $confProduct->setStoreId($storeID)
            ->setAttributeSetId( Mage::getModel('catalog/product')->getDefaultAttributeSetId() )
            ->setCategoryIds(array(2,3,4))
            ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
            ->setStatus(1)
            ->setTaxClassId(0);

        foreach ($dataProdConfig as $key => $value) {
            $confProduct->setData($key, $value);
        }

        $stockConfig =  array(
            'use_config_manage_stock' => '1',
            'inventory_manage_stock' => '1',
            'manage_stock' => '1',
            'min_sale_qty' => '1',
            'max_sale_qty' => '',
            'is_in_stock' => '1',
        );
        $confProduct->setStockData( $stockConfig );

        $confProduct->save();
        $sku = $dataProdConfig['sku'];
        foreach ($dataProdConfig['images'] as $image) {
            $this->importImages($confProduct, $image, $sku);
        }

        return $this->updateConfigurableProduct($storeID, $confProduct->getId(), $dataProdConfig, $simpleProductIds);
    }

    /**
     * update configurable product in MG
     *
     * @param $storeID
     * @param $idProd
     * @param array $dataProdConfig
     * @param array $simpleProductIds
     * @return Mage_Catalog_Model_Product
     */
    public function updateConfigurableProduct($storeID, $idProd, $dataProdConfig = array() , $simpleProductIds = array()){
        $mainProduct = Mage::getModel('catalog/product')->load( $idProd );

        $childProducts = Mage::getModel('catalog/product_type_configurable')
            ->getUsedProducts(null, $mainProduct);

        foreach($childProducts as $child) {
            array_push( $simpleProductIds, $child->getId() );
        }

        $mainProduct->setConfigurableProductsData(array_flip($simpleProductIds));
        $productType = $mainProduct->getTypeInstance(true);
        $productType->setProduct($mainProduct);
        $attributesData = $productType->getConfigurableAttributesAsArray();
        if (empty($attributesData)) {
            // Auto generation if configurable product has no attribute
            $attributeIds = array();
            foreach ($productType->getSetAttributes() as $attribute) {
                if ($productType->canUseAttribute($attribute)) {
                    $attributeIds[] = $attribute->getAttributeId();
                }
            }
            $productType->setUsedProductAttributeIds($attributeIds);
            $attributesData = $productType->getConfigurableAttributesAsArray();
        }
        if (!empty($configurableAttributes)){
            foreach ($attributesData as $idx => $val) {
                if (!in_array($val['attribute_id'], $configurableAttributes)) {
                    unset($attributesData[$idx]);
                }
            }
        }
        $products = Mage::getModel('catalog/product')->getCollection()
            ->addIdFilter($simpleProductIds);
        if (count($products)) {
            foreach ($attributesData as &$attribute) {
                $attribute['label'] = $attribute['frontend_label'];
                $attributeCode = $attribute['attribute_code'];
                foreach ($products as $product) {
                    $product->load($product->getId());
                    $optionId = $product->getData($attributeCode);

                    $attribute['values'][$optionId] = array(
                        'value_index' => $optionId,
                        'is_percent' => 0,
                        'pricing_value' => $product->getPrice(),
                    );
                }
            }
            $mainProduct->setConfigurableAttributesData($attributesData);
            $mainProduct->save();
        }

        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $confProduct = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $idProd );
        foreach ($dataProdConfig as $key => $value) {
            $confProduct->setData($key, $value);
        }

        $confProduct->save();

        return $confProduct;
    }

}