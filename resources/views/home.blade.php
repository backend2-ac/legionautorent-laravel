@extends('base')@section('body_class')home-page<?php $__env->stopSection(); ?>
@section('preload')<link rel="preload" type="image/webp" as="image" href="{{ $hero_film['poster'] }}" fetchpriority="high"><?php $__env->stopSection(); ?>
@section('content')@include('components.hero_film')@include('components.city_sections')<?php $__env->stopSection(); ?>
