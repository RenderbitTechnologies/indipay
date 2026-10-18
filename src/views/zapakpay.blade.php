@extends('indipay::layout')

@section('action', $endPoint)

@section('fields')
        @foreach($params as $param_key=>$param_value)
        	@if($param_key=='returnUrl')
        		<input type="hidden" name="{{ $param_key }}" value="{{ $param_value  }}" />
        	@else
				<input type="hidden" name="{{ $param_key }}" value="{{ $param_value  }}" />
        	@endif

        @endforeach
        <input type="hidden" name="checksum" value="{{ $checksum }}" />
@endsection
