@extends('layouts.simple')

@section('body')
    <div class="container small pt-xl">

        <section class="card content-wrap auto-height">
            <h1 class="list-heading">Connected AI apps</h1>
            <p class="text-small text-muted">
                Apps connected through MCP can read the content you can view, as you.
                To connect a new app, add this MCP server URL in the app: <code>{{ $mcpUrl }}</code>
            </p>

            @if(count($connections) > 0)
                <div class="item-list my-m">
                    @foreach($connections as $connection)
                        <div class="item-list-row flex-container-row items-center wrap py-xs gap-x-m">
                            <div class="flex px-m py-xs min-width-m">
                                <strong>{{ $connection->client_name ?? 'Unknown app' }}</strong><br>
                                <span class="small text-muted">
                                    Connected {{ \Illuminate\Support\Carbon::parse($connection->created_at)->format('Y-m-d') }}
                                    @if($connection->last_used_at)
                                        &middot; last used {{ \Illuminate\Support\Carbon::parse($connection->last_used_at)->diffForHumans() }}
                                    @endif
                                </span>
                            </div>
                            <div class="flex px-m py-xs text-right">
                                <form action="{{ url('/mcp/connections/' . urlencode($connection->client_id)) }}" method="POST">
                                    {{ csrf_field() }}
                                    {{ method_field('delete') }}
                                    <button type="submit" class="button outline small">Disconnect</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-muted italic py-m">No apps are connected.</p>
            @endif
        </section>

    </div>
@stop
