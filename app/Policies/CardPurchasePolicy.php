<?php

namespace App\Policies;

use App\Models\CardPurchase;
use App\Models\User;

class CardPurchasePolicy
{
    public function view(User $user, CardPurchase $purchase): bool
    {
        return $user->id === $purchase->card->user_id;
    }

    public function delete(User $user, CardPurchase $purchase): bool
    {
        return $user->id === $purchase->card->user_id;
    }
}
