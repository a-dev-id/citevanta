<?php

namespace Database\Seeders;

use App\Models\Hotel;
use Illuminate\Database\Seeder;

class HotelSeeder extends Seeder
{
    public function run(): void
    {
        Hotel::firstOrCreate(['identifier' => 'nandini'], ['name' => 'Nandini Jungle by Hanging Gardens', 'aliases' => ['Nandini Jungle by Hanging Gardens', 'Nandini Jungle Resort', 'Nandini Jungle'], 'verification_url' => 'https://nandinibali.com/', 'official_websites' => ['https://nandinibali.com/'], 'verified_at' => now()]);
        Hotel::firstOrCreate(['identifier' => 'hanging-gardens-of-bali'], ['name' => 'Hanging Gardens of Bali', 'aliases' => ['Hanging Gardens of Bali'], 'verification_url' => 'https://hanginggardensofbali.com/', 'official_websites' => ['https://hanginggardensofbali.com/'], 'verified_at' => now()]);
        Hotel::firstOrCreate(['identifier' => 'four-seasons-sayan'], ['name' => 'Four Seasons Resort Bali at Sayan', 'aliases' => ['Four Seasons Resort Bali at Sayan', 'Four Seasons Sayan'], 'verification_url' => 'https://www.fourseasons.com/sayan/', 'official_websites' => ['https://www.fourseasons.com/sayan/'], 'verified_at' => now()]);
        Hotel::firstOrCreate(['identifier' => 'mandapa'], ['name' => 'Mandapa, a Ritz-Carlton Reserve', 'aliases' => ['Mandapa, a Ritz-Carlton Reserve', 'Mandapa'], 'verification_url' => 'https://www.ritzcarlton.com/en/hotels/dpsub-mandapa-a-ritz-carlton-reserve/overview/', 'official_websites' => ['https://www.ritzcarlton.com/en/hotels/dpsub-mandapa-a-ritz-carlton-reserve/'], 'verified_at' => now()]);
        Hotel::firstOrCreate(['identifier' => 'capella-ubud'], ['name' => 'Capella Ubud', 'aliases' => ['Capella Ubud'], 'verification_url' => 'https://capellahotels.com/en/capella-ubud', 'official_websites' => ['https://capellahotels.com/en/capella-ubud'], 'verified_at' => now()]);
        Hotel::firstOrCreate(['identifier' => 'buahan'], ['name' => 'Buahan, a Banyan Tree Escape', 'aliases' => ['Buahan, a Banyan Tree Escape'], 'verification_url' => 'https://www.banyantree.com/escape/indonesia/buahan-a-banyan-tree-escape', 'official_websites' => ['https://www.banyantree.com/escape/indonesia/buahan-a-banyan-tree-escape'], 'verified_at' => now()]);
        Hotel::firstOrCreate(['identifier' => 'the-kayon-jungle-resort'], ['name' => 'The Kayon Jungle Resort', 'aliases' => ['The Kayon Jungle Resort', 'Kayon Jungle Resort'], 'verification_url' => 'https://thekayon.com/the-kayon-jungle-resort/', 'official_websites' => ['https://thekayonjungleresort.com/', 'https://thekayon.com/the-kayon-jungle-resort/'], 'verified_at' => now()]);
        Hotel::firstOrCreate(['identifier' => 'como-shambhala-estate'], ['name' => 'COMO Shambhala Estate', 'aliases' => ['COMO Shambhala Estate'], 'verification_url' => 'https://www.comohotels.com/bali/como-shambhala-estate', 'official_websites' => ['https://www.comohotels.com/bali/como-shambhala-estate'], 'verified_at' => now()]);
    }
}
