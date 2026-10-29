<?php

namespace App\Enums;

/**
 * Sanctum token abilities. A token carries exactly one scope, so a customer
 * token can never satisfy an admin-scoped field and vice versa.
 */
enum TokenAbility: string
{
    case Admin = 'admin';
    case Customer = 'customer';
}
