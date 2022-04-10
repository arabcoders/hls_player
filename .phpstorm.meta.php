<?php

namespace PHPSTORM_META;

override(\App\Libs\Container::get(0), map(['' => '@']));
override(\App\Libs\Extenders\PSRContainer::get(0), map(['' => '@']));
override(\Psr\Container\ContainerInterface::get(0), map(['' => '@']));
override(\League\Container\ReflectionContainer::get(0), map(['' => '@']));
