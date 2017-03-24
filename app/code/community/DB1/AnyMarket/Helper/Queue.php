<?php

class DB1_AnyMarket_Helper_Queue extends DB1_AnyMarket_Helper_Data
{

    private function addInLog($storeID, $logMessage, $IdItem){
        $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
        $anymarketlog->setLogDesc( $logMessage );
        $anymarketlog->setLogId($IdItem);
        $anymarketlog->setStatus("0");
        $anymarketlog->setStores(array($storeID));
        $anymarketlog->save();
    }

    /**
     * add item in current queue
     *
     * @param $IdItem
     * @param $typeItem
     * @param $tableItem
     */
    public function addQueue($storeID, $IdItem, $typeItem, $tableItem){
        $queueItemCheck = Mage::getModel('db1_anymarket/anymarketqueue')->setStoreId($storeID)
                                                                        ->load($IdItem, 'nmq_id');
        if( !$queueItemCheck->getNmqId() ){
            $queueItem = Mage::getModel('db1_anymarket/anymarketqueue');
            $queueItem->setNmqId($IdItem);
            $queueItem->setNmqType($typeItem);
            $queueItem->setNmqTable($tableItem);
            $queueItem->setStores(array($storeID));
            $queueItem->save();

            $this->addInLog($storeID, Mage::helper('db1_anymarket')->__('Added item in queue: ').$IdItem.", ".$typeItem.", ".$tableItem, $IdItem);
        }
    }

    /**
     * remove item from queue
     *
     * @param $idItem
     */
    public function removeQueue($idItem){
        $anymarketQueueDel = Mage::getModel('db1_anymarket/anymarketqueue');
        $anymarketQueueDel->setId($idItem)->delete();
    }

    /**
     * process queue
     */
    public function processQueue($typeExec){
        $qtyItensImport = (int)Mage::getConfig()->getNode('default/queue_qty/qty');
        $itens = Mage::getModel('db1_anymarket/anymarketqueue')
                ->getCollection()
                ->setPageSize($qtyItensImport)
                ->setCurPage(1);

        foreach($itens->getData() as $item) {
            $IdItemQueue = $item['nmq_id'];

            $anymarketQueue = Mage::getModel('db1_anymarket/anymarketqueue')->load($item['entity_id']);
            $arrValueStore = array_values($anymarketQueue->getStoreId());
            $storeID = array_shift($arrValueStore);

            $storeID = ($storeID != null && $storeID != "0") ? $storeID : Mage::app()->getDefaultStoreView()->getId();

            $cronEnabled = Mage::getStoreConfig('anymarket_section/anymarket_cron_group/anymarket_queue_field', $storeID);
            if($cronEnabled == '1' || $typeExec == "FORCE") {
                $typeSincProd = Mage::getStoreConfig('anymarket_section/anymarket_integration_prod_group/anymarket_type_prod_sync_field', $storeID);

                $this->addInLog($storeID, Mage::helper('db1_anymarket')->__('Processing item: ').$IdItemQueue, $IdItemQueue);
                $typImp = $item['nmq_type'];
                if ($item['nmq_table'] == 'ORDER') {
                    try {
                        if ($typImp == 'IMP') {
                            Mage::helper('db1_anymarket/order')->getSpecificOrderFromAnyMarket($IdItemQueue, "notoken", $storeID);
                        } else {
                            $anymarketorders = Mage::getModel('db1_anymarket/anymarketorders')->setStoreId($storeID);
                            $anymarketorders->load($IdItemQueue, 'nmo_id_order');
                            //Export
                            if ($anymarketorders->getNmoStatusInt() != "Não integrado (AnyMarket)") {
                                $idOrderToLoad = ($anymarketorders->getNmoIdOrder() == null) ? $IdItemQueue : $anymarketorders->getNmoIdOrder();
                                $Order = Mage::getModel('sales/order')->loadByIncrementId( $idOrderToLoad );
                                Mage::helper('db1_anymarket/order')->updateOrCreateOrderAnyMarket($storeID, $Order);
                            }
                        }

                    } catch (Exception $e) {
                        Mage::logException($e);
                    }
                } else if ($item['nmq_table'] == 'STOCK') {
                        if ($typImp == 'EXP') {
                            $product = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $IdItemQueue );
                            if ($product) {
                                $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
                                $ProdStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
                                Mage::helper('db1_anymarket/product')->updatePriceStockAnyMarket($storeID, $product->getId(), $ProdStock->getQty(), $product->getData($filter));
                            }
                        } else {
                            $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
                            $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

                            $headers = array(
                                "Content-type: application/json",
                                "Accept: */*",
                                "gumgaToken: " . $TOKEN
                            );

                            $listTransmissions = array();
                            array_push($listTransmissions, array(
                                    "id" => $IdItemQueue,
                                    "token" => "notoken"
                                )
                            );

                            $JSON = json_encode($listTransmissions);
                            Mage::helper('db1_anymarket/product')->getSpecificFeedProduct($storeID, json_decode($JSON), $headers, $HOST);
                        }
                } else if ($item['nmq_table'] == 'PRODUCT') {
                    // EXPORT PRODUCT
                    $typeSincOrder = Mage::getStoreConfig('anymarket_section/anymarket_integration_order_group/anymarket_type_order_sync_field', $storeID);
                    $anymarketproducts = Mage::getModel('db1_anymarket/anymarketproducts')->setStoreId($storeID);
                    $anymarketproducts->load($IdItemQueue, 'nmp_id');

                    if (($typImp == 'EXP') && ($typeSincProd == 0)) {
                        $idAnymarket = null;
                        try {
                            $product = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $IdItemQueue );
                            if ($product == null || $product->getId() == null) {
                                continue;
                            }
                            $anymarketproducts->setStatus('1')->setIsMassupdate(true)->save();
                            $idAnymarket = $this->getIdInAnymarketBySku($storeID, $product);
                            Mage::helper('db1_anymarket/product')->prepareForSendProduct($storeID, $product);
                        } catch (Exception $e) {
                            Mage::logException($e);
                        }

                        // TRATA STOCK
                        if ($idAnymarket != null) {
                            if ($typeSincOrder == 1) {
                                $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
                                $ProdStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
                                Mage::helper('db1_anymarket/product')->updatePriceStockAnyMarket($storeID, $product->getId(), $ProdStock->getQty(), $product->getData($filter));
                            } else {
                                Mage::helper('db1_anymarket/product')->getStockProductAnyMarket($storeID, $product->getId());
                            }
                        }
                    }else if ($typImp == 'IMP'){
                        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
                        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

                        $headers = array(
                            "Content-type: application/json",
                            "Accept: */*",
                            "gumgaToken: " . $TOKEN
                        );

                        $listTransmissions = array();
                        array_push($listTransmissions, array(
                                "id" => $IdItemQueue,
                                "token" => "notoken"
                            )
                        );

                        $JSON = json_encode($listTransmissions);
                        Mage::helper('db1_anymarket/product')->getSpecificFeedProduct($storeID, json_decode($JSON), $headers, $HOST);
                    }

                }
                $this->addInLog($storeID, Mage::helper('db1_anymarket')->__('Item processed: ').$IdItemQueue, $IdItemQueue);
                $this->removeQueue($item['entity_id']);
            }
        }

    }

    /**
     * process Orders By CRON
     */
    public function processOrders(){
        $allStores = Mage::helper('db1_anymarket')->getAllStores();
        foreach ($allStores as $store) {
            $storeID = $store['store_id'];
            $cronEnabled = Mage::getStoreConfig('anymarket_section/anymarket_cron_group/anymarket_order_field', $storeID);
            if( $cronEnabled == '1' ) {
                Mage::helper('db1_anymarket/order')->getFeedOrdersFromAnyMarket($storeID);
            }
        }

    }


    /**
     * process Orders with erros 01 By CRON
     */
    public function processOrdersWithError01(){
        $allStores = Mage::helper('db1_anymarket')->getAllStores();
        foreach ($allStores as $store) {
            $storeID = $store['store_id'];
            $cronEnabled = Mage::getStoreConfig('anymarket_section/anymarket_cron_group/anymarket_order_field', $storeID);

            if( $cronEnabled == '1' ) {
                $ordersError = Mage::getModel('db1_anymarket/anymarketorders')
                    ->getCollection()
                    ->addFilter('nmo_status_int', 'ERROR 01');

                foreach ($ordersError as $order) {
                    Mage::helper('db1_anymarket/queue')->addQueue($storeID, $order->getData('nmo_id_seq_anymarket'), 'IMP', 'ORDER');
                }
            }
        }
    }

    /**
     * process Orders with erros 02 By CRON
     */
    public function processOrdersWithError02(){
        $allStores = Mage::helper('db1_anymarket')->getAllStores();
        foreach ($allStores as $store) {
            $storeID = $store['store_id'];
            $cronEnabled = Mage::getStoreConfig('anymarket_section/anymarket_cron_group/anymarket_order_field', $storeID);
            if( $cronEnabled == '1' ) {
                $ordersError = Mage::getModel('db1_anymarket/anymarketorders')
                    ->getCollection()
                    ->addFilter('nmo_status_int', 'ERROR 02');

                foreach ($ordersError as $order) {
                    Mage::helper('db1_anymarket/queue')->addQueue($storeID, $order->getNmoIdOrder(), 'EXP', 'ORDER');
                }
            }
        }
    }

    /**
     * process Stocks By CRON
     */
    public function processStocks(){
        $qtyItensImport = (int)Mage::getConfig()->getNode('default/queue_qty/qty');
        $itens = Mage::getModel('db1_anymarket/anymarketqueue')
            ->getCollection()
            ->setPageSize($qtyItensImport)
            ->setCurPage(1);

        foreach($itens->getData() as $item) {
            $IdItemQueue = $item['nmq_id'];

            $anymarketQueue = Mage::getModel('db1_anymarket/anymarketqueue')->load($item['entity_id']);
            $arrValueStore = array_values($anymarketQueue->getStoreId());
            $storeID = array_shift($arrValueStore);

            $storeID = ($storeID != null && $storeID != "0") ? $storeID : Mage::app()->getDefaultStoreView()->getId();
            if ($item['nmq_table'] == 'STOCK') {
                $product = Mage::getModel('catalog/product')->setStoreId($storeID)->load( $IdItemQueue );

                // TRATA STOCK
                if ($product) {
                    $typeSincOrder = Mage::getStoreConfig('anymarket_section/anymarket_integration_order_group/anymarket_type_order_sync_field', $storeID);
                    if ($typeSincOrder == 1) {
                        $filter = strtolower(Mage::getStoreConfig('anymarket_section/anymarket_attribute_group/anymarket_preco_field', $storeID));
                        $ProdStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
                        Mage::helper('db1_anymarket/product')->updatePriceStockAnyMarket($storeID, $product->getId(), $ProdStock->getQty(), $product->getData($filter));
                    }
                }
                $this->removeQueue($item['entity_id']);
            }
        }
    }

    /**
     * process Clean Logs By CRON
     */
    public function processCleanLogs(){
        $from = date("Y-m-d H:m:s", strtotime("-3 months"));
        $to   = date("Y-m-d H:m:s", strtotime("-73 years"));
        $collection = Mage::getResourceModel('db1_anymarket/anymarketlog_collection')
            ->addFieldToFilter('updated_at', array('from'=> $to, 'to'=> $from ))
            ->load();

        $contLogs = 0;
        foreach ($collection->getItems() as $anymarketlog) {
            $anymarketlog->delete();
            $contLogs += 1;
        }

        return $contLogs;
    }

    /**
     * process Reindex by CRON
     */
    public function processReindex()
    {
        $allStores = Mage::helper('db1_anymarket')->getAllStores();
        foreach ($allStores as $store) {
            $storeID = $store['store_id'];
            $cronEnabled = Mage::getStoreConfig('anymarket_section/anymarket_cron_group/anymarket_reindex_field', $storeID);
            if( $cronEnabled == '1' ) {
                $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
                foreach ($processes as $process) {
                    if( $process->getData("mode") == "manual" ) {
                        $process->reindexEverything();
                    }
                }
            }
        }
    }

}