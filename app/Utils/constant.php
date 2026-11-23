<?php
/*write all text constants in alphabetic order*/
const CODE = 'code';
const COLOR = 'color';
const DISCOUNT = 'discount';
const DESCRIPTION = 'description';
const DIGITAL = 'digital';
const EN = 'en';
const FLAT = 'flat';
const FLAG = 'flag';
const GT = 'gt';
const DEFAULT_DATA_LIMIT = 25;
/* The most decimal places a point-of-sale sale may be added up and charged in. Six is the storage scale
   App\Services\OperationalCosts\Support\Money already rounds money to, and three is as far as any
   live currency goes (KWD, BHD, OMR). Read by App\Services\Pos\PosSaleTotal, which needs its integer
   grid bounded; deliberately NOT read by usdToDefaultCurrency, whose answer must not change for a
   setting the admin form allows. */
const MAX_CURRENCY_DECIMAL_POINTS = 6;
/* Digits below a minor unit at which a money figure is rounded to shake off binary floating-point noise
   before it is floored or cast to an integer. Far coarser than the noise (which is relative 1e-16) and far
   finer than any money figure a till can ring up. */
const MONEY_NOISE_DIGITS = 6;
const MIN = 'min';
const MULTI_CURRENCY = 'multi_currency';
const NUMERIC = 'numeric';
const IMAGE = 'image';
const IMAGE_NAME = 'image_name';
const IMAGES = 'images';
const LANG = 'lang';
const NAME = 'name';
const PHYSICAL = 'physical';
const PERCENTAGE = 'percent';
const REQUIRED = 'required';
const SUCCESS = 'success';
const TAX = 'tax';
const URI = 'uri';
const URL = 'url';
const USD = 'usd';
const UNIQUE = 'unique';
const VIEW = 'newCounter';
const FILE_NAME = 'file_name';
const ROUTE = 'route';
const MAXIMUM_IMAGE_UPLOAD_SIZE = '2MB';
const MAXIMUM_MEDIA_UPLOAD_SIZE = '2MB';
const DASHBOARD_DATA_LIMIT = 6;
const DASHBOARD_TOP_SELL_DATA_LIMIT = 5;
const TABLE_VIEW = 'table_view';
const FILTER_PRODUCT_DATA_LIMIT = 10;
