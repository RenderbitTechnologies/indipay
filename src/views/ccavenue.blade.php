@extends('indipay::layout')

@section('action', $endPoint)

@section('fields')
        <input type=hidden name=encRequest value="{{ $encRequest }}">
        <input type=hidden name=access_code value="{{ $accessCode }}">
@endsection
