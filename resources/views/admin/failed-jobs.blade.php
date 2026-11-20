<x-layouts::app title="Failed jobs" :breadcrumbs="['Admin' => null, 'Failed jobs' => null]">
    <x-flash />

    <x-card title="Failed queue jobs" subtitle="Failed scan calls are also recorded on their scan run. Retry puts a job back on the queue." :flush="true" :collapsible="false">
        @if ($jobs->isEmpty())
            <x-empty-state icon="fa-circle-check" text="No failed jobs. Everything ran fine." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col" class="cv-num">ID</th>
                            <th scope="col">Job</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Failed at</th>
                            <th scope="col">Error</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($jobs as $job)
                            <tr>
                                <td class="cv-num">{{ $job->id }}</td>
                                <td>{{ class_basename(json_decode($job->payload, true)['displayName'] ?? 'Unknown') }}</td>
                                <td>{{ $job->queue }}</td>
                                <td>{{ $job->failed_at }}</td>
                                <td class="small">{{ Str::limit(Str::before($job->exception, "\n"), 160) }}</td>
                                <td class="text-end text-nowrap">
                                    <form method="POST" action="{{ route('admin.failed-jobs.retry', $job->uuid) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Retry</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.failed-jobs.delete', $job->uuid) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Delete this failed job?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($jobs->hasPages())
            <x-slot:footer>{{ $jobs->links() }}</x-slot:footer>
        @endif
    </x-card>
</x-layouts::app>
