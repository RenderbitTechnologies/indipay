@extends('indipay::layout')

@section('action', $end_point)

@section('fields')
        @foreach ($data as $param_key=>$param_name)
        	<input type=hidden name="{{$param_key}}" value="{{ $param_name }}">
        @endforeach
@endsection
