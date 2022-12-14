# Кастомная выгрузка товаров с [Русский Свет](https://russvet.ru/)
#### Строго прошу не кидать тапками!

Данный скрипт по PRODAT&PRICAT выгружает товары в каталог сайта как простые товары.

## Запуск

```php
Import::implement();
```

Для корректной работы на сервере необходимо иметь **_Memcache_**!

## Загрузка PRODAT

```php
Import::getProdatFromFtp();
```

## Загрузка PRICAT

```php
Import::getPricatFromFtp();
```

## Highload-блок: цвет

```text
AsproMaxColorReference.xml
```
Это Highload-блок используется, чтобы указать цвет товара.

![AsproMaxColorReference.png](img/AsproMaxColorReference.png)

## Highload-блок: кастомные разделы

```text
custom_sections.xml
```

Это Highload-блок используется, чтобы настраивать каталог под себя.

![custom_sections.png](img/custom_sections.png)

## Highload-блок: наценка по бренду

```text
MarkupOnProductByBrand.xml
```

Это Highload-блок используется, чтобы добавить наценку на цены товаров.\
**Чтобы добавить наценку на все товары, полья бренд оставьте пустым.**

![MarkupOnProductByBrand.png](img/MarkupOnProductByBrand.png)