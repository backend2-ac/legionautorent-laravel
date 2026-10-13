@extends('base')@section('body_class')home-page<?php $__env->stopSection(); ?>
@section('preload')<link rel="preload" type="image/webp" as="image" href="{{ dv('hero_film.mobile',get_defined_vars()) }}" media="(max-width:899px)" fetchpriority="high"><link rel="preload" type="image/webp" as="image" href="{{ dv('hero_film.poster',get_defined_vars()) }}" media="(min-width:900px)" fetchpriority="high"><?php $__env->stopSection(); ?>
@section('content')@include('components.mercedes_preview')@include('components.city_sections')<?php $__env->stopSection(); ?>
