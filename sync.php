<?php
namespace ImportProduct;
use Bitrix\Highloadblock\HighloadBlockTable as HLBT;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
ini_set('max_execution_time', '100000');

class Import
{
    private static int $productsIblockId = #CATALOG_IBLOCK_ID#;
    private static int $hlbl = #COLOR_HIGHLOAD_BLOCK_ID#;
    private static int $hlblCustomSection = #CUSTOM_SECTION_HIGHLOAD_BLOCK_ID#;
    private static int $hlblMarkupForBrand = #MARKUP_FOR_BRAND_HIGHLOAD_BLOCK_ID#;
    private static $products;
    private static $productsToAdd;
    private static $productsToUpdate;
    private static string $ftp_server = 'service.russvet.ru';
    private static string $ftp_port = '21021';
    public static string $local_file = '/home/bitrix/www/sync/xml/'; // path to xml files
    private static $memcache;


    /**
     * Импортирует модули для работы скрипта
     * @param $moduleNames
     * @return void
     */
    private static function includeModules($moduleNames)
    {
        foreach ($moduleNames as $moduleName) {
            \CModule::IncludeModule($moduleName);
        }
        echo 'Импорт модулей: ' . implode(", ", $moduleNames) . "<br>";
    }

    /**
     * Добавляет логи
     * @param $log
     * @param string $fileName Названия файла для логов</br>
     * По умолчанию логи хранятся в log.txt в текущем разделе
     * @return void
     */
    private static function addLog($log, string $fileName = 'log.txt')
    {
        $log = date('Y-m-d H:i:s') . ' ' . print_r($log, true);
        file_put_contents(__DIR__ . '/'.$fileName, $log . PHP_EOL, FILE_APPEND);
    }

    /**
     * Упрощает артикул товара
     * @param $param
     * @return array|string|string[]
     */
    private static function getVendorProdNum($param)
    {
        $vendor = str_replace([' ', '.'], '', $param);
        return str_replace(['/', '-', '(', ')'], '_', $vendor);
    }

    public static function implement()
    {
        if(self::checkSync()){
            try {
                self::includeModules(['iblock', 'catalog', 'main', 'highloadblock']);
                $files = self::getFiles();
                foreach ($files as $file) {
                    if (strpos($file, 'xml') !== false) {
                        self::setMemcache();
                        self::addLog("Выполняется $file");
                        self::$products = '';
                        self::$products = self::getProductsXml(self::$local_file . $file);
                        self::getAddOrUpdateProducts(self::$products);
                        self::addProducts();
                        self::updateProducts();

                        unlink(self::$local_file.$file);
                        self::$productsToAdd = [];
                        self::$productsToUpdate = [];
                    }
                }
            }catch (\Exception $e){
                self::addLog($e->getMessage());
            }
            self::unlockSync();
        }
    }

    /**
     * Проверка memcache
     * @return bool|void
     */
    private static function checkSync(){
        self::$memcache = new \Memcache;
        self::$memcache->connect('localhost', 11211) or die ("Не могу подключиться к memcached");
        if(self::$memcache->get('import_status') == 'work'){
            return false;
        }
        else{
            return true;
        }
        self::addLog('А memcache то установлен?');
        return false;
    }

    /**
     * Установка memcache
     * Время хранения 5 часа
     * @return void
     */
    private static function setMemcache(){
        self::$memcache->set('import_status', 'work', null, time() + 18000);
    }

    /**
     * Очистка memcache
     * @return void
     */
    public static function unlockSync(){
        self::$memcache->delete('import_status');
    }

    /**
     * Сканирует раздел xml
     * @return array|false
     */
    private static function getFiles()
    {
        self::unZipXml();
        return scandir(__DIR__ . '/xml');
    }

    /**
     * Получает PRODAT из сервера Русский свет
     * @return void
     */
    public static function getProdatFromFtp()
    {
        self::addLog('Выгрузка PRODAT началась!');
        $ftp_user_name = #LOGIN#;
        $ftp_user_pass = #PASSWORD#;
        $ftp = ftp_connect(self::$ftp_server, self::$ftp_port);
        $login_result = ftp_login($ftp, $ftp_user_name, $ftp_user_pass);
        if ((!$ftp) || (!$login_result)) {
            self::addLog('Не удалось установить соединение с FTP-сервером PRODAT!');
            exit;
        }
        ftp_pasv($ftp, true);
        $contents = ftp_mlsd($ftp, "/sklad");

        foreach ($contents as $content) {
            if ($content['type'] == 'dir' && $content['name'] == 'ROSTOV') {
                $regionFolderContents = ftp_nlist($ftp, '/sklad/' . $content['name']);
                foreach ($regionFolderContents as $regionFolderContent) {
                    $filePwd = explode("/", $regionFolderContent);
                    $file = array_pop($filePwd);
                    try {
                        ftp_get($ftp, self::$local_file . $file, $regionFolderContent, FTP_BINARY);
                    } catch (\Exception $e) {
                        self::addLog("Не удалось завершить операцию запись файла $regionFolderContent");
                        self::addLog($e);
                    }
                }
            }
        }
        self::addLog("Операцию скачивания файлов PRODAT с фтп завершено!");
        ftp_close($ftp);
    }

    /**
     * Получает PRICAT из сервера Русский свет
     * @return void
     */
    public static function getPricatFromFtp()
    {
        self::addLog('Выгрузка PRICAT началась!');
        $ftp_user_name = #LOGIN#;
        $ftp_user_pass = #PASSWORD;
        $ftp = ftp_connect(self::$ftp_server, self::$ftp_port);
        $login_result = ftp_login($ftp, $ftp_user_name, $ftp_user_pass);
        if ((!$ftp) || (!$login_result)) {
            self::addLog('Не удалось установить соединение с FTP-сервером PRICAT!');
            exit;
        }
        ftp_pasv($ftp, true);
        $contents = ftp_mlsd($ftp, "/");

        foreach ($contents as $content) {
            if ($content['type'] == 'dir' && $content['name'] == 'pricat') {
                $regionFolderContents = ftp_nlist($ftp, '/' . $content['name']);
                foreach ($regionFolderContents as $regionFolderContent) {
                    $filePwd = explode("/", $regionFolderContent);
                    $file = array_pop($filePwd);
                    try {
                        ftp_get($ftp, self::$local_file . $file, $regionFolderContent, FTP_BINARY);
                    } catch (\Exception $e) {
                        self::addLog("Не удалось завершить операцию запись файла $regionFolderContent");
                        self::addLog($e);
                    }
                }
            }
        }
        self::addLog("Операцию скачивания файлов PRICAT с фтп завершено!");
        ftp_close($ftp);
    }

    /**
     * Если в разделе xml есть zip, то разархивирует его
     * @return void
     */
    private static function unZipXml()
    {
        $files = scandir(__DIR__ . '/xml');
        $zip = new \ZipArchive;

        foreach ($files as $file) {
            if (strpos($file, 'zip') !== false) {
                $res = $zip->open('/home/bitrix/www/sync/xml/' . $file);
                if ($res === TRUE) {
                    $zip->extractTo('/home/bitrix/www/sync/xml/');
                    $zip->close();
                    unlink('/home/bitrix/www/sync/xml/' . $file);
                } else {
                    self::addLog("Не удалось распаковать файла $file");
                }
            }
        }
    }

    /**
     * Получает путь файла и интерпретирует его в XML объект
     * @param $path
     * @return \$1|\SimpleXMLElement|void
     */
    private static function getProductsXml($path)
    {
        libxml_use_internal_errors(true);
        $simplexml_load_file = simplexml_load_file($path);
        if (!$simplexml_load_file) {
            $errors = libxml_get_errors();
            $filePwd = explode("/", $path);
            $file = array_pop($filePwd);
            self::addLog('Не удалось интерпретировать ' . $file . ' в объект. ' . $errors[0]->message . ' line: ' . $errors[0]->line);
            rename($path, self::$local_file."error-xml/$file");
        } else {
            return $simplexml_load_file;
        }
    }

    /**
     * Получает товары из XML
     * @param $products
     * @return void
     */
    private static function getAddOrUpdateProducts($products)
    {
        $i = 0;
        $xmlIds = [];
        $toUpdate = [];

        foreach ($products->{'DocDetail'} as $product) {
            $xmlIds[] = self::getVendorProdNum((string)$product->{'VendorProdNum'});
            // Конвертируем в обычный массив
            self::$productsToAdd[self::getVendorProdNum((string)$product->{'VendorProdNum'})] = json_decode(json_encode($product), true);
        }

        $res = \CIBlockElement::GetList(
            [],
            [
                "IBLOCK_ID" => self::$productsIblockId,
                "XML_ID" => $xmlIds
            ],
            false,
            false,
            ["ID", "XML_ID", "CODE"]
        );

        while ($arFields = $res->fetch()) {
            $toUpdate[$arFields['ID']] = $arFields['XML_ID'];
        }

        foreach (self::$productsToAdd as $key => $product) {
            $mathKey = '';
            $mathKey = array_search($key, $toUpdate);
            if ($mathKey) {
                self::$productsToUpdate[$key]['XML_DATA'] = $product;
                self::$productsToUpdate[$key]['SITE_ID'] = $mathKey;
                unset(self::$productsToAdd[$key]);
            }
        }
    }

    /**
     * Добавления товара
     * @return void
     * @throws \Exception
     */
    private static function addProducts()
    {
        foreach (self::$productsToAdd as $product) {
            $PROP = [];
            $el = new \CIBlockElement;
            $arrNewElement = self::generateDataToAddInIBlock($product);
            $elId = $el->Add($arrNewElement);
            if(!$elId){
                self::addLog("Не удалось добавить товар с кодом ".$product['VendorProdNum']);
                self::addLog($el->LAST_ERROR, 'err_add_product.txt');
                self::addLog($arrNewElement, 'err_add_product.txt');
            }else{
                if(self::addBrand($product["Brand"])) $PROP['BRAND'] = self::addBrand($product["Brand"]);
                if(self::getVendorProdNum($product["VendorProdNum"])) $PROP['CML2_ARTICLE'] = is_array($product["VendorProdNum"]) ? self::getVendorProdNum($product["VendorProdNum"][0]) : self::getVendorProdNum($product["VendorProdNum"]);
                $country = is_array($product["Country"]["Value"]) ? $product["Country"]["Value"][0] : $product["Country"]["Value"];
                if($country) $PROP['PROP_2084'] = $country;
                if(!empty($PROP)){
                    \CIBlockElement::SetPropertyValuesEx($elId, self::$productsIblockId, $PROP);
                }
                self::updatePrice($elId, $product);
            }
        }
    }

    /**
     * Обновления товара
     * @return void
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws \Exception
     */
    private static function updateProducts(){
        foreach (self::$productsToUpdate as $product) {
            $PROP = [];
            $el = new \CIBlockElement;
            $arrElement = self::generateDataToAddInIBlock($product['XML_DATA']);
            unset($arrElement["CODE"]);

            $elId = $el->Update($product['SITE_ID'], $arrElement, true);
            if(!$elId){
                self::addLog("Не удалось обновить товар с кодом ".$product['XML_DATA']['VendorProdNum']);
                self::addLog($el->LAST_ERROR, 'err_update_product.txt');
                self::addLog($arrElement, 'err_update_product.txt');
            }else{
                if(self::addBrand($product['XML_DATA']["Brand"])) $PROP['BRAND'] = self::addBrand($product['XML_DATA']["Brand"]);
                if(self::getVendorProdNum($product['XML_DATA']["VendorProdNum"])) $PROP['CML2_ARTICLE'] = is_array($product['XML_DATA']["VendorProdNum"]) ? self::getVendorProdNum($product['XML_DATA']["VendorProdNum"][0]) : self::getVendorProdNum($product['XML_DATA']["VendorProdNum"]);
                $country = is_array($product['XML_DATA']["Country"]["Value"]) ? $product['XML_DATA']["Country"]["Value"][0] : $product['XML_DATA']["Country"]["Value"];
                if($country) $PROP['PROP_2084'] = $country;
                if(!empty($PROP)){
                    \CIBlockElement::SetPropertyValuesEx($elId, self::$productsIblockId, $PROP);
                }

                self::updatePrice($product['SITE_ID'], $product['XML_DATA']);
                if($product['XML_DATA']['FeatureETIMDetails']['FeatureETIM']){
                    self::updateProperties($product['SITE_ID'], $product['XML_DATA']);
                }
            }
        }
    }

    /**
     * Генерации данных для товара
     * @param $product
     * @return array
     * @throws \Exception
     */
    private static function generateDataToAddInIBlock($product): array
    {
        $iblockSectionId = self::checkCustomSection(self::getIdSection([$product['ParentProdGroup'], $product['ProductGroup']]));
        $newElement = [
            "IBLOCK_SECTION_ID" => $iblockSectionId,
            "CODE" => \Cutil::translit($product["ProductName"],"ru",["replace_space" => "-", "replace_other" => "-"]),
            "EXTERNAL_ID" => is_array($product["VendorProdNum"]) ? self::getVendorProdNum($product["VendorProdNum"][0]) : self::getVendorProdNum($product["VendorProdNum"]),
            "IBLOCK_ID" => self::$productsIblockId,
            "NAME" => $product["ProductName"],
            "ACTIVE" => "Y",
            "SORT" => 500,
        ];

        if($product['Image']['Value']){
            if(is_array($product['Image']['Value'])){
                $newElement["DETAIL_PICTURE"] = \CFile::MakeFileArray($product['Image']['Value'][0], 'image/png');
                $newElement["PREVIEW_PICTURE"] = \CFile::MakeFileArray($product['Image']['Value'][0], 'image/png');
            }else{
                $newElement["DETAIL_PICTURE"] = \CFile::MakeFileArray($product['Image']['Value'], 'image/png');
                $newElement["PREVIEW_PICTURE"] = \CFile::MakeFileArray($product['Image']['Value'], 'image/png');
            }
        }

        return $newElement;
    }

    /**
     * @param int $sectId ID раздела для проверки, есть ли для него замена.
     * @return int
     * @throws \Exception
     */
    private static function checkCustomSection(int $sectId): int
    {
        $currentSectionId = $sectId;
        try {
            $entity_data_class = self::GetEntityDataClass(self::$hlblCustomSection);
        } catch (ObjectPropertyException|SystemException|ArgumentException $e) {
            throw new \Exception($e);
        }

        $rsData = $entity_data_class::getList(array(
            "select" => array("*"),
            "order" => array("ID" => "ASC"),
            "filter" => array("UF_OLD_SECTION_ID"=>$sectId)
        ));
        $arData = $rsData->Fetch();

        if ($arData["UF_NEW_SECTION_ID"]) {
            $currentSectionId = $arData["UF_NEW_SECTION_ID"];
        }

        return $currentSectionId;
    }

    /**
     * Получаем ID раздела для добавления товара
     * @param array $params Разделы первого и второго уровня
     * @return false|int|mixed|null
     */
    private static function getIdSection(array $params)
    {
        $rsParentSection = \CIBlockSection::GetList([], ['IBLOCK_ID' => self::$productsIblockId, "NAME" => $params[0]]);
        $parentSection = $rsParentSection->GetNext();
        if (empty($parentSection['ID'])) {
            $parentSectionId = self::addSection($params[0]);
            if (isset($params[1])){
                return self::addSubSection($parentSectionId, $params[1]);
            }else{
                return $parentSectionId;
            }
        } else {
            $rsSect = \CIBlockSection::GetList(['left_margin' => 'asc', 'section'=>$parentSection['ID']], ['IBLOCK_ID' => self::$productsIblockId, "NAME" => $params[1]]);
            $arSect = $rsSect->GetNext();
            if (empty($arSect['ID'])){
                return self::addSubSection($parentSection['ID'], $params[1]);
            }else{
                return $arSect['ID'];
            }
        }
    }

    /**
     * Добавляет родительский раздел
     * @param string $section Название раздела
     * @return false|int|mixed|null
     */
    private static function addSection(string $section){
        $bs = new \CIBlockSection;
        $arParams = array("replace_space" => "-", "replace_other" => "-");
        $arFields = array(
            "ACTIVE" => "Y",
            "IBLOCK_ID" => self::$productsIblockId,
            "NAME" => $section,
            "CODE" => \Cutil::translit($section, "ru", $arParams),
        );
        $sectionId = $bs->Add($arFields);

        if ($sectionId) {
            self::addLog("Новый раздел $section создан!");
        } else {
            self::addLog("Не удалось создать раздел $section");
        }
        return $sectionId;
    }

    /**
     * Добавляет дочерний раздел
     * @param int $parentSectionId ID родительского раздела
     * @param string $nameSubSection Названия подраздела
     * @return false|int|mixed|null
     */
    private static function addSubSection(int $parentSectionId, string $nameSubSection)
    {
        $bs = new \CIBlockSection;
        $arFields = array(
            "ACTIVE" => "Y",
            "IBLOCK_ID" => self::$productsIblockId,
            "IBLOCK_SECTION_ID" => $parentSectionId,
            "NAME" => $nameSubSection,
            "CODE" => \Cutil::translit($nameSubSection, "ru", ["replace_space" => "-", "replace_other" => "-"]),
        );
        $sectionId = $bs->Add($arFields);

        if ($sectionId) {
            self::addLog("Новый подраздел $nameSubSection создан!");
            return $sectionId;
        } else {
            self::addLog("Не удалось создать подраздел $nameSubSection");
        }
    }

    /**
     * Установка цены и параметры товара
     * @param $elId
     * @param $product
     * @return void
     */
    private static function updatePrice($elId, $product){
        $arFields["ID"] = $elId;

        if($product['QTY']){
            $arFields["QUANTITY"] = $product['QTY'];
        }
        if($product['Weight']['Value']){
            $arFields["WEIGHT"] = $product['Weight']['Value'];
        }
        if($product['Dimension']['Width'] && $product['Dimension']['Height']){
            $arFields["WIDTH"] = $product['Dimension']['Width'];
            $arFields["HEIGHT"] = $product['Dimension']['Height'];
        }

        if(!\CCatalogProduct::Add($arFields)){
            self::addLog("Не удалось добавить параметры товара к элементу каталога с ID $elId");
        }else{
            if($product['Price2']){
                $res = \CIBlockElement::GetList(Array(), ["IBLOCK_ID"=>33, "ACTIVE"=>"Y", "NAME"=>$product["Brand"]], false, Array("nPageSize"=>10), ["ID"]);
                $arFields = $res->GetNext();

                $entity_data_class = self::GetEntityDataClass(self::$hlblMarkupForBrand);
                $rsData = $entity_data_class::getList(array(
                    "select" => array("*"),
                    "order" => array("ID" => "ASC"),
                    "filter" => array("UF_BRAND"=>$arFields["ID"])
                ));
                $arData = $rsData->Fetch();

                if(!$arData){
                    $rsData = $entity_data_class::getList(array(
                        "select" => array("*"),
                        "order" => array("ID" => "ASC"),
                        "filter" => array("UF_BRAND"=>'')
                    ));
                    $arData = $rsData->Fetch();
                }
                $price = $product['Price2'];

                if($arData['UF_MARKUP']){
                    $price = self::add_percent($product['Price2'], $arData['UF_MARKUP']);
                    //self::addLog("$elId цена ".$product['Price2'].", цена с наценкой ".$arData['UF_MARKUP']."% - $price");
                }else{
                    self::addLog("Для товара $elId цена не изменилась");
                }

                self::setPrice($elId, 1, $price);
            }
        }
    }

    /**
     * @param $price
     * @param $percent
     * @return float|int
     */
    private static function add_percent($price, $percent): float|int
    {
        return $price + ($price * $percent / 100);
    }

    /**
     * Добавления или обновления цены товара
     * @param $elId
     * @param $idPrice
     * @param $price
     * @return void
     */
    private static function setPrice($elId, $idPrice, $price){
        $res = \CPrice::GetList(
            [],
            [
                "PRODUCT_ID" => $elId,
                "CATALOG_GROUP_ID" => $idPrice
            ]
        );

        $arFields = [
            "PRODUCT_ID" => $elId,
            "CATALOG_GROUP_ID" => $idPrice,
            "PRICE" => $price,
            "CURRENCY" => "RUB",
        ];

        if ($arr = $res->Fetch())
        {
            $idUpPrice = \CPrice::Update($arr["ID"], $arFields);
            if (!$idUpPrice){
                self::addLog("Не удалось обновить цену у товара с ID $elId. Причина: $idUpPrice->LAST_ERROR");
            }
        }
        else
        {
            $idAddPrice = \CPrice::Add($arFields);
            if(!$idAddPrice){
                self::addLog("Не удалось добавить цену у товара с ID $elId. Причина: $idAddPrice->LAST_ERROR");
            }
        }
    }

    /**
     * Установка свойство товара
     * @param $edId
     * @param $product
     * @return void
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private static function updateProperties($edId, $product)
    {
        $arFile = [];
        $propObj = new \CIBlockProperty;

        if(is_array($product['FeatureETIMDetails']['FeatureETIM'])){
            $arFeature = self::getFeature($product);
            $propertiesObject = $propObj->GetList([], ['IBLOCK_ID' => self::$productsIblockId, 'ACTIVE' => 'Y']);
            $newProperties = self::addProperty($propertiesObject, $arFeature);

            $properties = $propObj->GetList([], ['IBLOCK_ID' => self::$productsIblockId, 'ACTIVE' => 'Y']);
            while ($prop_fields = $properties->Fetch())
            {
                if(array_key_exists($prop_fields['NAME'], $arFeature)){
                    if($prop_fields['CODE'] === "COLOR_REF2"){
                        self::addColor($edId, $prop_fields['CODE'], $arFeature[$prop_fields['NAME']]);
                    }else{
                        $propValue = $arFeature[$prop_fields['NAME']][1] ? implode(" ", $arFeature[$prop_fields['NAME']]) : $arFeature[$prop_fields['NAME']][0];
                        //$upElID = \CIBlockElement::SetPropertyValuesEx($edId, self::$productsIblockId, [$prop_fields['CODE'] => $propValue]);
                        $upElID = \CIBlockElement::SetPropertyValueCode($edId, $prop_fields['ID'], $propValue);
                        if(!$upElID){
                            self::addLog("Не удалось установить свойство товара с ID $edId. Причина: $upElID->LAST_ERROR");
                        }
                    }
                }
            }
            foreach ($newProperties as $key=>$newProperty) {
                $upElID = \CIBlockElement::SetPropertyValueCode($edId, $key, $newProperty["VALUE"]);
                if(!$upElID){
                    self::addLog("Не удалось установить свойство товара с ID $edId. Причина: $upElID->LAST_ERROR");
                }
            }
        }

        if(is_array($product["Image"]["Value"])){
            self::clearMultipleProperty($edId, 'MORE_PHOTO');
            array_shift($product["Image"]["Value"]);
            foreach ($product["Image"]["Value"] as $img){
                $arFile[] = ["VALUE" => \CFile::MakeFileArray($img), 'image/png'];
            }
            if(!\CIBlockElement::SetPropertyValueCode($edId, 'MORE_PHOTO', $arFile)){
                self::addLog("Не удалось добавить свойство Картинки для товара с ID $edId");
            }
        }
    }

    /**
     * Получает характеристики товара из XML и возвращает как ассоциативный массив ключ: названия
     * @param $product
     * @return array
     */
    private static function getFeature($product): array
    {
        $features = [];
        foreach ($product['FeatureETIMDetails']['FeatureETIM'] as $featureETIMDetail) {
            $features[$featureETIMDetail['FeatureName']] = [
                !is_array($featureETIMDetail['FeatureValue']) ? $featureETIMDetail['FeatureValue'] : $featureETIMDetail['FeatureValue'][0],
                !is_array($featureETIMDetail['FeatureUom']) ? $featureETIMDetail['FeatureUom'] : $featureETIMDetail['FeatureUom'][0]
            ];
        }
        return $features;
    }

    /**
     * Очищает множественного свойства
     * @param $edId
     * @param string $propertyCode Код множественного свойства
     * @return void
     */
    private static function clearMultipleProperty($edId, string $propertyCode){
        $db_props = \CIBlockElement::GetProperty(self::$productsIblockId, $edId, "sort", "asc", Array("CODE"=>$propertyCode));
        while($ar_props = $db_props->Fetch())
        {
            if ($ar_props["VALUE"])
            {
                $arr[$ar_props['PROPERTY_VALUE_ID']] = Array("VALUE" => Array("del" => "Y"));
                if (!\CIBlockElement::SetPropertyValueCode($edId, $propertyCode, $arr )){
                    self::addLog("Не удалось очистить множественного свойства товара с ID $edId");
                }
                \CFile::Delete($ar_props['VALUE']);
            }
        }
    }

    /**
     * Добавляет новый бренд
     * @param $brandName
     * @return int|void
     */
    private static function addBrand($brandName){
        $rsParentSection = \CIBlockElement::GetList([], ['IBLOCK_ID' => 33, "CODE" => \Cutil::translit($brandName,"ru",["replace_space" => "-", "replace_other" => "-"]), 'ACTIVE' => 'Y']);
        $parentSection = $rsParentSection->GetNext();
        if(empty($parentSection['ID'])){
            $el = new \CIBlockElement;
            $brand = [
                "IBLOCK_ID" => 33,
                "NAME" => $brandName,
                "CODE" => \Cutil::translit($brandName,"ru",["replace_space" => "-", "replace_other" => "-"]),
                "ACTIVE" => "Y",
                "PREVIEW_TEXT" => $brandName,
            ];

            $brandId = $el->Add($brand);
            if(!$brandId){
                self::addLog("Не удалось добавить бренд $brandName");
            }else{
                self::addLog("Добавлен бренд $brandName");
                return $brandId;
            }
        }else{
            return $parentSection['ID'];
        }
    }

    /**
     * Добавляет новые свойства и устанавливает им значения
     * @param $properties
     * @param array $arFeature
     * @return array
     */
    private static function addProperty($properties, array $arFeature): array
    {
        $arAllProps = [];
        $arProp = [];
        $arNewProps = [];
        while ($prop_fields = $properties->Fetch())
        {
            if (strpos($prop_fields["CODE"], 'PROP') !== false) {
                $arAllProps[] = explode('_', $prop_fields["CODE"])[1];
            }
            $arProp[$prop_fields['NAME']] = $prop_fields['VALUE'];
        }
        $maxPropCode = max($arAllProps);

        $arDif = array_diff_key($arFeature, $arProp);
        foreach ($arDif as $key=>$value) {
            if(strlen($key)>2){
                $maxPropCode++;
                $arFields = Array(
                    "IBLOCK_ID" => self::$productsIblockId,
                    "NAME" => $key,
                    "ACTIVE" => "Y",
                    "SORT" => "100",
                    "CODE" => "PROP_".$maxPropCode,
                    "PROPERTY_TYPE" => "S",
                    "SMART_FILTER" => "Y",
                    'FEATURES' => [
                        [
                            'IS_ENABLED'=>'Y',
                            'MODULE_ID'=>'iblock',
                            'FEATURE_ID'=>'DETAIL_PAGE_SHOW'
                        ]
                    ]
                );
                $ibp = new \CIBlockProperty;
                $newPropId = $ibp->Add($arFields);
                if (!$newPropId) {
                    self::addLog("Не удалось добавить свойство $key");
                }else{
                    $arNewProps[$newPropId] = [
                        "NAME" => $arFields["NAME"],
                        "VALUE" => $value[1] ? implode(" ", $value) : $value[0]
                    ];
                }
            }
        }
        return $arNewProps;
    }

    /**
     * Поиск Highload-блока по его ID
     * @param int $HlBlockId ID highload
     * @return string
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private static function GetEntityDataClass(int $HlBlockId): string
    {
        if (empty($HlBlockId) || $HlBlockId < 1)
        {
            return false;
        }
        $hlblock = HLBT::getById($HlBlockId)->fetch();
        $entity = HLBT::compileEntity($hlblock);
        $entity_data_class = $entity->getDataClass();
        return $entity_data_class;
    }

    /**
     * Устанавливает значения для свойства цвет
     * @param $edId
     * @param $propCode
     * @param $propValue
     * @return void
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private static function addColor($edId, $propCode, $propValue){
        $color = "";
        $entity_data_class = self::GetEntityDataClass(self::$hlbl);

        $rsData = $entity_data_class::getList(array(
            "select" => array("*"),
            "order" => array("ID" => "ASC"),
            "filter" => array("UF_NAME"=>$propValue)
        ));

        while($arData = $rsData->Fetch()){
            $color = $arData;
        }

        if(!\CIBlockElement::SetPropertyValueCode($edId, $propCode, $color["UF_XML_ID"])){
            self::addLog("Не удалось добавить цвет ". $color["UF_NAME"]);
        }
    }
}

//Import::implement();

//Import::getProdatFromFtp();
//Import::getPricatFromFtp();