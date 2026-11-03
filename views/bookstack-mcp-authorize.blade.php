@extends('layouts.simple')

@section('body')
    <div class="container very-small pt-xl">

        <div class="card content-wrap auto-height">
            <h1 class="list-heading">Connect {{ $client->client_name }}</h1>

            <p>
                <strong>{{ $client->client_name }}</strong> wants read access to {{ setting('app-name') }}
                as <strong>{{ $user->name }}</strong>.
            </p>

            <p>It will be able to:</p>
            <ul>
                <li>Search, list and read the shelves, books, chapters and pages that you can view.</li>
                <li>Read page comments.</li>
            </ul>
            <p>It will not be able to change or delete anything, or see content your roles can't access.</p>

            <p class="text-small text-muted">
                After approving you will be sent to <strong>{{ $redirectHost }}</strong>.
                Only approve if you started this connection yourself and recognise that address.
                You can disconnect apps at any time from <a href="{{ url('/mcp/connections') }}">Connected AI apps</a>.
            </p>

            <form action="{{ url('/mcp/oauth/authorize') }}" method="POST" class="text-right mt-l">
                {{ csrf_field() }}
                @foreach($params as $name => $value)
                    @if(!is_null($value))
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach

                <button type="submit" name="decision" value="deny" class="button outline">Deny</button>
                <button type="submit" name="decision" value="approve" class="button">Approve</button>
            </form>
        </div>

    </div>
@stop
