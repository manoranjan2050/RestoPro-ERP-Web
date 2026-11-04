<?php

namespace App\Livewire;

use App\Services\Analytics;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Annual Summary')]
class AnnualSummary extends Component
{
    #[Url]
    public ?int $fy = null;

    public function render()
    {
        $years = Analytics::years();
        if (! $this->fy || ! isset($years[$this->fy])) {
            $this->fy = array_key_first($years);
        }

        return view('livewire.annual-summary', [
            'a' => Analytics::build($this->fy),
            'years' => $years,
        ]);
    }
}
