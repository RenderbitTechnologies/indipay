@extends('indipay::layout')

@section('action', $endPoint)

@section('fields')
        @foreach($params as $param_key=>$param_value)
        	<input type="hidden" name="{{ $param_key }}" value="{{ $param_value  }}" />

        @endforeach
        <input type="hidden" name="checksum" value="{{ $checksum }}" />
@endsection
