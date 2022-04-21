<?php

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_admin_before.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/iblock/prolog.php");
IncludeModuleLangFile(__FILE__);
IncludeModuleLangFile($_SERVER["DOCUMENT_ROOT"] . BX_ROOT . "/modules/main/interface/admin_lib.php");
global $APPLICATION;
$APPLICATION->SetTitle('Дебагер почтовых сообщений');
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_admin_after.php");
require $_SERVER["DOCUMENT_ROOT"] . "/local/Email.php";


if ($_GET["ID"]) {
    $APPLICATION->restartBuffer();
    $eventId = $_GET["ID"];
    $messages = Email::executeEvent($eventId);
    if (empty($messages)) {
        echo "<b>Не найден активный шаблон для этого события</b><br>";
        die();
    }
    foreach ($messages as $message) {
        echo "Кому:  <br>" . $message['TO'] . "<br><br>";
        echo "Тема: <br>" . $message['SUBJECT'] . "<br><br>";
        echo "Тело:  <br>" . $message['BODY'];
        echo "<br><br><br><br>";
    }
    die();
}

$grid_options = new Bitrix\Main\Grid\Options('report_list');
$sort = $grid_options->GetSorting([
    'sort' => ['ID' => 'DESC'],
    'vars' => ['by' => 'by', 'order' => 'order']
]);

$nav_params = $grid_options->GetNavParams();

$nav = new Bitrix\Main\UI\PageNavigation('report_list');
$nav->allowAllRecords(false)
    ->setPageSize($nav_params['nPageSize'])
    ->initFromUri();

$filter = [];
$filterOption = new Bitrix\Main\UI\Filter\Options('report_list');
$filterData = $filterOption->getFilter([]);
foreach ($filterData as $k => $v) {
    if (!in_array($k, ['PRESET_ID', 'FIND', 'FILTER_APPLIED', 'FILTER_ID'])) {
        $filter[$k] = $v;
    }
}

if ($filterData['FIND'] && empty($filter['EVENT_NAME'])) {
    $filter['EVENT_NAME'] = "%" . $filterData['FIND'] . "%";
}

$res = Bitrix\Main\Mail\Internal\EventTable::getList([
    'filter' => $filter,
    'select' => [
        "*",
    ],
    'offset' => $nav->getOffset(),
    'limit' => $nav->getLimit(),
    'order' => $sort['sort']
]);
$nav->setRecordCount(Bitrix\Main\Mail\Internal\EventTable::getCount($filter));

$res = $res->fetchAll();
$rows = [];
foreach ($res as $item) {
    $id = $item['ID'];

    $link = $APPLICATION->GetPopupLink(array(
            "URL" => "/bitrix/admin/email_debugger_admin_page.php?ID=$id",
            "PARAMS" => array(
                "width" => 780,
                "height" => 570,
                "resizable" => true,
                "min_width" => 780,
                "min_height" => 400
            )
        )
    );

    $fields = $item['C_FIELDS'];
    foreach ($fields as &$field) {
        $field =htmlentities($field);
    }

    $templatesLink = '/bitrix/admin/message_admin.php?PAGEN_1=1&SIZEN_1=20&lang=ru&set_filter=Y&adm_filter_applied=0&find_type=subject&find_type_id='.$item['EVENT_NAME'];

    $rows[] = [
        "data" => [
            "ID" => $id,
            "EVENT_NAME" => $item['EVENT_NAME'],
            //"MESSAGE_ID" =>$item['MESSAGE_ID'],
            'LID' => $item['LID'],
            'C_FIELDS' => json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'DATE_INSERT' => $item['DATE_INSERT'],
            'LANGUAGE_ID' => $item['LANGUAGE_ID'],
        ],
        'actions' => [
            [
                'text' => 'Оригинал',
                'onclick' => 'document.location.href="/bitrix/admin/perfmon_row_edit.php?lang=ru&table_name=b_event&pk%5BID%5D=' . $id . '"'
            ],
            [
                'text' => 'Скомпилировать',
                'onclick' => $link
            ],
            [
                'text' => 'Шаблоны',
                'onclick' => 'document.location.href="'.$templatesLink.'"'
            ]
        ]
    ];
}
?>

<?php
$APPLICATION->IncludeComponent('bitrix:main.ui.filter', '', [
    'FILTER_ID' => 'report_list',
    'GRID_ID' => 'report_list',
    'FILTER' => [
        ['id' => 'ID', 'name' => 'ID записи', 'type' => 'number'],
        ['id' => 'EVENT_NAME', 'name' => 'Код события'],
        //['id' => 'DATE_INSERT', 'name' => 'Дата добавления', 'type' => 'date'],
    ],
    'ENABLE_LIVE_SEARCH' => true,
    'ENABLE_LABEL' => true
]);
?>
<?php


$columns = [
    ['id' => 'ID', 'name' => 'ID', 'sort' => 'ID', 'default' => true],
    ['id' => 'EVENT_NAME', 'name' => 'EVENT_NAME', 'sort' => 'EVENT_NAME', 'default' => true],
    ['id' => 'LID', 'name' => 'LID', 'sort' => 'LID', 'default' => true],
    ['id' => 'C_FIELDS', 'name' => 'C_FIELDS', 'sort' => 'C_FIELDS', 'default' => true],
    ['id' => 'DATE_INSERT', 'name' => 'DATE_INSERT', 'sort' => 'DATE_INSERT', 'default' => true],
    ['id' => 'LANGUAGE_ID', 'name' => 'LANGUAGE_ID', 'sort' => 'LANGUAGE_ID', 'default' => true],
];

$APPLICATION->includeComponent(
    "bitrix:main.ui.grid",
    "",
    [
        'GRID_ID' => 'report_list',
        'COLUMNS' => $columns,
        'SHOW_ROW_CHECKBOXES' => false,
        'NAV_OBJECT' => $nav,
        "ROWS" => $rows,
        'PAGE_SIZES' => [
            ['NAME' => "5", 'VALUE' => '5'],
            ['NAME' => '10', 'VALUE' => '10'],
            ['NAME' => '20', 'VALUE' => '20'],
            ['NAME' => '50', 'VALUE' => '50'],
            ['NAME' => '100', 'VALUE' => '100']
        ],
        'AJAX_MODE' => 'N',
        'ALLOW_COLUMNS_SORT' => true,
        'ALLOW_COLUMNS_RESIZE' => true,
        'ALLOW_HORIZONTAL_SCROLL' => true,
        'ALLOW_SORT' => true,
        'ALLOW_PIN_HEADER' => true,
        'SHOW_PAGESIZE' => true,
        'SHOW_TOTAL_COUNTER' => true,
        'ENABLE_COLLAPSIBLE_ROWS' => true,
        'SHOW_PAGINATION' => true,
        'SHOW_NAVIGATION_PANEL' => true
    ]
);
