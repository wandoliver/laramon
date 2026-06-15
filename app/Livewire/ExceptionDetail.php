<?php

namespace App\Livewire;

use App\Livewire\Concerns\BuildsCharts;
use App\Models\ExceptionGroup;
use App\Models\Instance;
use App\Models\Sample;
use App\Services\BucketQuery;
use App\Support\TimeRange;
use Livewire\Attributes\Url;
use Livewire\Component;

class ExceptionDetail extends Component
{
    use BuildsCharts;

    public Instance $instance;

    public ExceptionGroup $group;

    #[Url]
    public string $range = '24h';

    public function mount(Instance $instance, string $fingerprint): void
    {
        $this->instance = $instance;
        $this->group = ExceptionGroup::query()
            ->where('instance_id', $instance->id)
            ->where('fingerprint', $fingerprint)
            ->firstOrFail();
    }

    public function render(BucketQuery $buckets)
    {
        $this->range = TimeRange::valid($this->range);

        $to = now()->getTimestamp();
        $from = $to - TimeRange::seconds($this->range);

        $series = $buckets->series($this->instance->id, 'exception', $this->group->fingerprint, $from, $to);

        $samples = Sample::query()
            ->where('instance_id', $this->instance->id)
            ->where('kind', 'exception')
            ->where('fingerprint', $this->group->fingerprint)
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return view('livewire.exception-detail', [
            'chart' => $this->singleSeriesChart($series, $from, $to, $buckets->step($from, $to), 'Occurrences', '#fb7185'),
            'samples' => $samples,
            'rangeCount' => $series->sum('count'),
        ])->title($this->group->class.' — '.config('app.name'));
    }
}
