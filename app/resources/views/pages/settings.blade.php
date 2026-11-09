@extends('layouts.app')

@section('body-class', 'page-settings')
@section('page-title', __('messages.settings.page_title'))

@section('content')
    <livewire:settings-form />
@endsection
