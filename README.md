# bePaid payment gateway plugin for Moodle.

[![](https://img.shields.io/github/v/release/Snickser/moodle-paygw_bepaid.svg)](https://github.com/Snickser/moodle-paygw_bepaid/releases)
[![Build Status](https://github.com/Snickser/moodle-paygw_bepaid/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/Snickser/moodle-paygw_bepaid/actions/workflows/moodle-ci.yml)

![alt text](https://raw.githubusercontent.com/Snickser/moodle-paygw_bepaid/473d0f0c18c1525a60b81fedc89eac2e45f5ed6f/pix/img.svg)

https://bepaid.by

## Возможности

+ Можно использовать пароль или кнопку для обхода платежа.
+ Сохраняет в базе номер курса и название группы студента.
+ Можно указать рекомендуемую цену, ограничить максимальную цену, или включить режим фиксированной цены.
+ Отображение продолжительности обучения (для enrol_yafee и mod_gwpaymets), если она установлена.
+ Поддержка пароля из модуля курса (mod_gwpaymets).
+ Оповещение пользователя при успешном платеже.
+ Рекуррентные платежи (только совместно с моим report_payments).
+ Поддержка функции доплаты пропущенного периода из enrol_yafee.

## Рекомендации

+ Moodle 4.3+
+ Для записи в курс используйте мой плагин "Зачисление за оплату" [enrol_yafee](https://github.com/Snickser/moodle-enrol_yafee)
+ Для контрольного задания используйте пропатченный мной плагин по ссылке [mod_gwpayments](https://github.com/Snickser/moodle-mod_gwpayments/tree/dev)
+ Для ограничения доступности используйте пропатченный мной плагин по ссылке [availability_gwpayments](https://github.com/Snickser/moodle-availability_gwpayments/tree/dev)
+ Плагин просмотра отчётов и отключения регулярных платежей [report_payments](https://github.com/Snickser/moodle-report_payments/tree/dev)

## INSTALLATION

Download the latest **paygw_bepaid.zip** and unzip the contents into the **/payment/gateway** directory. Or upload it from Moodle plugins adminnistration interface.<br>

1. Install the plugin
2. Enable the bepaid payment gateway
3. Create a new payment account
4. Configure the payment account against the bepaid gateway using your pay ID
5. Enable the 'Enrolment on Payment' enrolment method
6. Add the 'Enrolment on Payment' method to your chosen course
7. Set the payment account, enrolment fee, and currency

This plugin supports only basic functionality, but everything changes someday...
