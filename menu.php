<?php
// подключаю API Cockpit для получения данных из CMS
include 'cockpit/bootstrap.php';

// определяю заголовки
header('Content-Type: text/xml; charset=utf-8');

// склеиваю строку доменного имени
$domainName = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : $_SERVER["SERVER_NAME"];
$basePath   = "http://$domainName/";


// описываю категории товаров
$categories = array(
  1 => array(
    'title' => 'Пицца 30 см',
    'name'  => 'pizza',
  ),
  2 => array(
    'title' => 'Пицца 45 см',
    'name'  => 'pizza',
  ),
  3 => array(
    'title' => 'Wok',
    'name'  => 'wok',
  ),
  4 => array(
    'title' => 'Суши',
    'name'  => 'sushi',
  ),
  5 => array(
    'title' => 'Прочие товары',
    'name'  => 'other',
  ),
);

// описываю функцию подготовки товара.
function prepareCategory($categories, $categoryId, $basePath, $haveVesions) {
  // забираем имя из описания категорий
  $categoryName = $categories[$categoryId]['name'];

  // забираем товары из Cockpit по имени категории
  $categoryRaw  = cockpit('collections')->find($categoryName, [
    'filter' => [
      'published' => true,
    ],
  ]);

  // трансформируем полученные товары
  return array_map(
    function ($item) use ($categoryId, $basePath, $haveVesions) {
      // формируем базовый товар
      $product = array(
        'original_id' => $item['_id'],
        'category_id' => $categoryId,
        'name'        => $item['title'],
        'description' => html_entity_decode(strip_tags($item['description'])),
        'price'       => $item['price'],
        'picture'     => $basePath . $item['photo']['path'],
        'modified'    => $item['_modified'],
      );

      // если установлена единица измерения, оставляем только цифры
      if (isset($item['measure'])) {
        $pattern = '/\D+/i';
        $product['weight'] = preg_replace($pattern, '', $item['measure']);
      }

      // если есть версии, выделяем вес и цену версии
      if ($haveVesions && isset($item['versions'])) {
        $product['versions'] = array_map(function ($item) {
          $measure = $item['value']['measure'];

          // если изменятся версии, это не будет работать
          $pattern = '/(30|45|гр|см|\s|\(|\))/i';
          $weight  = preg_replace($pattern, '', $measure);
          return array(
            'price'  => $item['value']['price'],
            'weight' => $weight,
          );
        }, $item['versions']);
      }
      return $product;
    },
    $categoryRaw
  );
}

// подгатавливаем пиццы
$pizzas = prepareCategory($categories, 1, $basePath, true);

// создаем из пицц категории 30 и 45 см.
$pizza_30 = array();
$pizza_45 = array();
foreach ($pizzas as $pizza) {
  // пропускаем картошку, которая оказалась в пиццах не случайно
  if ($pizza['original_id'] === '5d39ec05b041fdoc1782748728' || $pizza['original_id'] === '5d39ec05b065cdoc985533307') {
    continue;
  }

  // если первая версия — кладем в пиццы 30 см
  if (isset($pizza['versions'][0])) {
    $pizza_30_item             = $pizza;
    $pizza_30_item['price']    = $pizza['versions'][0]['price'];
    $pizza_30_item['weight']   = $pizza['versions'][0]['weight'];
    $pizza_30_item['versions'] = null;
    array_push($pizza_30, $pizza_30_item);
  }

  // если вторая версия — кладем в пиццы 45 см, пропускаем Кальцоне
  if (isset($pizza['versions'][1]) && $pizza['original_id'] !== '5d39ec05b4d96doc839779212') {
    $pizza_45_item                = $pizza;
    $pizza_45_item['category_id'] = 2;
    $pizza_45_item['price']       = $pizza['versions'][1]['price'];
    $pizza_45_item['weight']      = $pizza['versions'][1]['weight'];
    $pizza_45_item['versions']    = null;
    array_push($pizza_45, $pizza_45_item);
  }
}

// подготавливаем воки, суши и другие товары
$wok   = prepareCategory($categories, 3, $basePath, false);
$sushi = prepareCategory($categories, 4, $basePath, false);
$other = prepareCategory($categories, 5, $basePath, false);

// собираем товары в один массив
$products = array_merge($pizza_30, $pizza_45, $wok, $sushi, $other);

// создаем новый массив из дат последнего изменения товаров
$productModifiedTimes = array_map(function ($product) {
  return $product['modified'];
}, $products);

// берем максимальную из них и подправляем два часа
$last_modified_time = max($productModifiedTimes) + (60 * 60 * 2);

// форматируем
$last_modified = date("Y-m-d H:i", $last_modified_time);

// выводим все в XML
// https://github.com/spatie/array-to-xml
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>

<!DOCTYPE dc_catalog SYSTEM "http://www.delivery-club.ru/xml/dc.dtd">
<dc_catalog last_update="<?=$last_modified?>">
  <delivery_service>
    <categories>
      <?php foreach ($categories as $id => $category): ?>
        <category id="<?=$id?>"><?=$category['title']?></category>
      <?php endforeach;?>
    </categories>
    <products>
      <?php foreach ($products as $id => $product): ?>
        <product id="<?=$id + 6?>">
          <category_id><?=$product['category_id']?></category_id>
          <name><?=$product['name']?></name>
          <description><?=$product['description']?></description>
          <picture><?=$product['picture']?></picture>
          <weight><?=$product['weight']?></weight>
          <price><?=$product['price']?></price>
        </product>
      <?php endforeach;?>
    </products>
  </delivery_service>
</dc_catalog>