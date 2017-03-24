<?php

class DB1_AnyMarket_Helper_Image extends DB1_AnyMarket_Helper_Data
{

    /**
     * //GET LAST ITEM IN CTRL TABLE
     * @param $key
     * @param $value
     *
     * @return Object
     */
    private function getLastCtrlReg($key, $value){
        $loadedImagCtrl = Mage::getModel('db1_anymarket/anymarketimage')
            ->getCollection()
            ->addFilter($key, $value);

        return Mage::getModel('db1_anymarket/anymarketimage')->load( $loadedImagCtrl->getLastItem()->getData('entity_id'), 'entity_id');
    }

    /**
     * //obtem as imagens do produto(Config ou Simples)
     * @param $storeID
     * @param $product
     *
     * @return array
     */
    public function getImagesOfProduct($storeID, $product, $ArrVariationValues){
        if(!$product) {
            return null;
        }
        if($product->getStatus() != 1){
            return null;
        }
        $transformToHttp = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_transform_http_image_field', $storeID);
        $itemsIMG = array();
        $galleryData = $product->getMediaGalleryImages();

        $exportImage = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_export_image_field', $storeID);
        foreach ($galleryData as $g_image) {
            $infoImg = getimagesize($g_image['url']);
            $imgSize = filesize($g_image['path']);
            $processImage = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_transform_process_image_field', $storeID);
            if ($processImage == 0) {
                if ($exportImage == 0 && ($infoImg[0] != "") && ((float)$infoImg[0] < 350 || (float)$infoImg[1] < 350 || $imgSize > 4100000)){
                    $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                    $anymarketlog->setLogDesc('Error on export image - ' . $g_image['url'] . ' - Width: ' . $infoImg[0] . ' - Height: ' . $infoImg[1] . ' - Size: ' . $imgSize );
                    $anymarketlog->setLogId($product->getSku());
                    $anymarketlog->setStatus("1");
                    $anymarketlog->setStores(array($storeID));
                    $anymarketlog->save();
                } else {
                    $urlImageImport = $g_image['url'];
                    $defaultImage = $product->getImage();
                    $isMain =  strpos($urlImageImport, $defaultImage) === false ? false : true;
                    if ($ArrVariationValues) {
                        foreach ($ArrVariationValues as $value) {
                            if ($transformToHttp != 0) {
                                $urlImageImport = str_replace("https", "http", $urlImageImport);
                            }
                            $itemsIMG[] = array(
                                "main" => $isMain,
                                "url" => $urlImageImport,
                                "variation" => $value,
                            );
                        }
                    } else {
                        if ($transformToHttp != 0) {
                            $urlImageImport = str_replace("https", "http", $urlImageImport);
                        }

                        $itemsIMG[] = array(
                            "main" => $isMain,
                            "url" => $urlImageImport
                        );
                    }

                }
            } else {
                if (count($product->getMediaGalleryImages()) > 0) {
                    $with = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_width_image_field', $storeID);
                    $height = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_height_image_field', $storeID);
                    foreach ($product->getMediaGalleryImages() as $_image) {
                        if (((int)$with > 0) && ((int)$height > 0)) {
                            $thumbnail12 = Mage::helper('catalog/image')->init($product, 'image', $_image->getFile())->resize($with, $height);
                        } else {
                            $thumbnail12 = Mage::helper('catalog/image')->init($product, 'image', $_image->getFile());
                        }
                        $thumbnail13 = str_replace('/webApps/migration/productapi/new/', '/', $thumbnail12);
                        $urlImageImport = $thumbnail13;
                        if ($ArrVariationValues) {
                            foreach ($ArrVariationValues as $value) {
                                if ($transformToHttp != 0) {
                                    $urlImageImport = str_replace("https", "http", $urlImageImport);
                                }
                                $itemsIMG[] = array(
                                    "main" => false,
                                    "url" => $urlImageImport,
                                    "variation" => $value,
                                );
                            }
                        } else {
                            if ($transformToHttp != 0) {
                                $urlImageImport = str_replace("https", "http", $urlImageImport);
                            }

                            $itemsIMG[] = array(
                                "main" => true,
                                "url" => $urlImageImport
                            );
                        }

                    }
                }
            }
        }

        return $itemsIMG;
    }

    private function getMediaEntityGalleryDirect($valueId){
        $connection = Mage::getSingleton('core/resource')->getConnection('core_read');
        $sql        = "SELECT * FROM `catalog_product_entity_media_gallery` WHERE `value_id`=".$valueId;
        $rows       = $connection->fetchAll($sql);

        return count($rows) > 0;
    }

    private function deleteImageAnymarket($storeID, $product, $headers, $imgRemove, $valueId){
        if( $valueId != null && $this->getMediaEntityGalleryDirect($valueId) ){
            return false;
        }

        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);
        $idAnymarket = $this->getIdInAnymarketBySku($storeID, $product);
        if($idAnymarket == null){
            return false;
        }

        $imgDelRet = $this->CallAPICurl("DELETE", $HOST."/v2/products/".$idAnymarket."/images/".$imgRemove, $headers, null);
        if ($imgDelRet['error'] == '1') {
            $anymarketlogDel = Mage::getModel('db1_anymarket/anymarketlog');

            if (is_string($imgDelRet['return'])) {
                $anymarketlogDel->setLogDesc('Error on delete image in Anymarket ('.$imgRemove.') - '.$imgDelRet['return']);
            } else {
                $anymarketlogDel->setLogDesc('Error on delete image in Anymarket ('.$imgRemove.') - '.json_encode($imgDelRet['return']));
            }

            $anymarketlogDel->setLogJson('');
            $anymarketlogDel->setLogId($product->getSku());
            $anymarketlogDel->setStatus("1");
            $anymarketlogDel->setStores(array($storeID));
            $anymarketlogDel->save();
        } else {
            $anymarketlogDel = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlogDel->setLogDesc('Deleted image from Anymarket ('.$imgRemove.')');
            $anymarketlogDel->setLogJson('');
            $anymarketlogDel->setLogId($product->getSku());
            $anymarketlogDel->setStatus("1");
            $anymarketlogDel->setStores(array($storeID));
            $anymarketlogDel->save();


            $anymarketCtrlImg = $this->getLastCtrlReg('id_image', $imgRemove);
            if( $anymarketCtrlImg->getData('value_id') != "" || $anymarketCtrlImg->getData('value_id') != null ) {
                $anymarketCtrlImg->delete();
            }
        }
    }

    /**
     *
     * Send Image to Anymarket
     *
     * @param $storeID
     * @param $product
     * @param $variation
     *
     * @return boolean
     */
    public function sendImageToAnyMarket($storeID, $product, $variation){
        if(!$product){
            return false;
        }

        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);
        $exportImage = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_export_image_field', $storeID);
        $transformToHttp = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_transform_http_image_field', $storeID);

        $headers = array(
            "Content-type: application/json",
            "gumgaToken: ".$TOKEN
        );

        $idAnymarket = $this->getIdInAnymarketBySku($storeID, $product);
        if($idAnymarket == null){
            $product = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $product->getId() );
        }

        $imgGetRet = $this->CallAPICurl("GET", $HOST."/v2/products/".$idAnymarket."/images", $headers, null);
        if($imgGetRet['error'] != '0') {
            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlog->setLogDesc('Error on get images from Anymarket (' . $idAnymarket . ') ');
            $anymarketlog->setStatus("1");
            $anymarketlog->setStores(array($storeID));
            $anymarketlog->save();

            return false;
        }

        $imgsProdAnymarket = $imgGetRet['return'];

        $arrAdd = array();
        $arrImgs = array();

        //OBTEM A GALERIA SEM A STORE
        $processImage = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_transform_process_image_field', $storeID);
        $imgsProdMagento = $product->getMediaGalleryImages();

        //CASO NAO ACHE, CARREGA PASSANDO A STORE
        if (count($imgsProdMagento) <= 0) {
            $productIMG = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $product->getId() );
            $imgsProdMagento = $productIMG->getMediaGalleryImages();
        }

        //CASO AINDA NAO ENCONTRAR E ELE TIVER UM PAI, CARREGA DO PAI
        if (count($imgsProdMagento) <= 0) {
            $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild( $product->getId() );
            if (isset($parentIds[0])) {
                $confID = $parentIds[0];
                $productConfig = Mage::getModel('catalog/product')->setStoreId($storeID)->load($confID);
                $exportSimpleSep = $productConfig->getData('exp_sep_simp_prod');
                if($exportSimpleSep == 1){
                    $imgsProdMagento = $productConfig->getMediaGalleryImages();
                }
            }
        }

        if (count($imgsProdMagento) > 0) {
            if( $processImage == 0 ) {
                foreach ($imgsProdMagento as $imgProdMagento) {
                    $loadedImagCtrl = $this->getLastCtrlReg('value_id', $imgProdMagento->getData('value_id'));

                    if( $loadedImagCtrl->getData('value_id') != "" && $loadedImagCtrl->getData('value_id') != null ) {
                        $imgValDeleted = $this->CallAPICurl("GET", $HOST."/v2/products/".$idAnymarket."/images/".$loadedImagCtrl->getData('id_image'), $headers, null);
                        if($imgValDeleted['error'] == '1'){
                            $loadedImagCtrl->delete();
                        }else{
                            continue;
                        }
                    }
                    $urlImage = $imgProdMagento->getData('url');
                    $infoImg = getimagesize($urlImage);
                    $imgSize = filesize($imgProdMagento->getData('path'));

                    if ($exportImage == 0 && ($infoImg[0] != "") && ((float)$infoImg[0] < 350 || (float)$infoImg[1] < 350 || $imgSize > 4100000)) {
                        $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                        $anymarketlog->setLogDesc('Error on export image - ' . $urlImage . ' - Width: ' . $infoImg[0] . ' - Height: ' . $infoImg[1] . ' - Size: ' . $imgSize );
                        $anymarketlog->setLogId($product->getSku());
                        $anymarketlog->setStatus("1");
                        $anymarketlog->setStores(array($storeID));
                        $anymarketlog->save();
                    } else {
                        $imgProdMagentoURL = $imgProdMagento->getData('url');
                        if ($transformToHttp != 0) {
                            $imgProdMagentoURL = str_replace("https", "http", $imgProdMagentoURL);
                        }
                        array_push($arrAdd, array('URL' => $imgProdMagentoURL, 'ID' =>$imgProdMagento->getData('value_id') ));
                    }
                }
            }else {
                $with = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_width_image_field', $storeID);
                $height = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_height_image_field', $storeID);
                foreach ($imgsProdMagento as $imgProdMagento) {
                    $loadedImagCtrl = $this->getLastCtrlReg('value_id', $imgProdMagento->getData('value_id'));

                    if( $loadedImagCtrl->getData('value_id') != "" && $loadedImagCtrl->getData('value_id') != null ) {
                        $imgValDeleted = $this->CallAPICurl("GET", $HOST."/v2/products/".$idAnymarket."/images/".$loadedImagCtrl->getData('id_image'), $headers, null);
                        if($imgValDeleted['error'] == '1'){
                            $loadedImagCtrl->delete();
                        }else{
                            continue;
                        }
                    }

                    if (((int)$with > 0) && ((int)$height > 0)) {
                        $thumbnail12 = Mage::helper('catalog/image')->init($product, 'image', $imgProdMagento->getFile())->resize($with, $height);
                    } else {
                        $thumbnail12 = Mage::helper('catalog/image')->init($product, 'image', $imgProdMagento->getFile());
                    }

                    $imgProdMagentoURL = str_replace('/webApps/migration/productapi/new/', '/', $thumbnail12);
                    if ($transformToHttp != 0) {
                        $imgProdMagentoURL = str_replace("https", "http", $imgProdMagentoURL);
                    }
                    array_push($arrAdd, array('URL' => $imgProdMagentoURL, 'ID' =>$imgProdMagento->getData('value_id') ));
                }
            }
        }

        //REMOVE IMAGE
        foreach ($imgsProdAnymarket as $imgProdAnymarket) {
            $exportImages = true;
            if ($variation) {
                if( !isset($imgProdAnymarket->variation) || $imgProdAnymarket->variation != $variation ){
                    $exportImages = false;
                }
            }

            if( $exportImages ) {
                $imgRemove = $imgProdAnymarket->id;
                $loadedImagCtrl = $this->getLastCtrlReg('id_image', $imgRemove);

                if( $loadedImagCtrl->getData('value_id') != "" || $loadedImagCtrl->getData('value_id') != null ) {
                    $deleteImage = true;
                    foreach ($imgsProdMagento as $imgProdMagento) {
                        $urlImage = $imgProdMagento->getData('value_id');
                        if( $urlImage == $loadedImagCtrl->getData('value_id') ){
                            $deleteImage = false;
                            break;
                        }
                    }
                    if($deleteImage){
                        $this->deleteImageAnymarket($storeID, $product, $headers, $imgRemove, $loadedImagCtrl->getData('value_id'));
                    }

                }else{
                    $this->deleteImageAnymarket($storeID, $product, $headers, $imgRemove, null);
                }
            }
        }

        // Add Image
        if( !empty($arrImgs) ){
            $returnProd['error'] = '1';
            $returnProd['json'] = '';

            $emptyFields = ' ';
            foreach ($arrImgs as $field) {
                $emptyFields .= $field.', ';
            }
            $returnProd['return'] = Mage::helper('db1_anymarket')->__('Product with inconsistency:').' '.$emptyFields;
            $this->saveLogsProds($storeID, "0", $returnProd, $product);
        }else {
            $defaultImage = $product->getImage();
            foreach ($arrAdd as $imgAddArr) {
                $imgAdd = $imgAddArr['URL'];

                $isMain =  strpos($imgAdd, $defaultImage) === false ? false : true;
                if ($variation) {
                    $JSONAdd = array(
                        "url" => $imgAdd,
                        "variation" => $variation,
                        "main" => $isMain
                    );
                } else {
                    $JSONAdd = array(
                        "url" => $imgAdd,
                        "main" => $isMain
                    );
                }

                $imgPostRet = $this->CallAPICurl("POST", $HOST."/v2/products/".$idAnymarket."/images", $headers, $JSONAdd);
                if ($imgPostRet['error'] == '1') {
                    $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                    $anymarketlog->setLogDesc('Error on export image ('.$idAnymarket.') - ' . is_string($imgPostRet['return']) ? $imgPostRet['return'] : json_encode($imgPostRet['return']));
                    $anymarketlog->setLogJson($imgPostRet['json']);
                    $anymarketlog->setLogId($product->getSku());
                    $anymarketlog->setStatus("1");
                    $anymarketlog->setStores(array($storeID));
                    $anymarketlog->save();
                } else {
                    $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                    $anymarketlog->setLogDesc('Exported image (' . $imgAdd . ')');
                    $anymarketlog->setLogJson($imgPostRet['json']);
                    $anymarketlog->setLogId($product->getSku());
                    $anymarketlog->setStatus("1");
                    $anymarketlog->setStores(array($storeID));
                    $anymarketlog->save();

                    $retJson = $imgPostRet['return'];
                    $anymarketImage = Mage::getModel('db1_anymarket/anymarketimage');
                    $anymarketImage->setValueId( $imgAddArr['ID'] );
                    $anymarketImage->setIdImage( $retJson->id );
                    $anymarketImage->save();
                }
            }
        }

    }

}