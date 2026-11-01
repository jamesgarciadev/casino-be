<?php

namespace App\Enums;

/**
 * What a market is about. Titles and outcome labels are built by the clients from the type plus the market's map,
 * half, round, line and side, so be stores no display text.
 */
enum MarketType: string
{
    // The whole match. Every game.
    case Winner = 'winner';

    // The whole series (best of 3 or more).
    case MapHandicap = 'map_handicap';
    case CorrectMapScore = 'correct_map_score';
    case TotalMaps = 'total_maps';
    case OddEvenMaps = 'odd_even_maps';

    // Rounds across the whole match, overtime included (CS2, Valorant).
    case RoundsHandicap = 'rounds_handicap';
    case TotalRounds = 'total_rounds';
    case TeamTotalRounds = 'team_total_rounds';

    // The map being played (CS2, Valorant).
    case MapWinner = 'map_winner';
    case Map1x2 = 'map_1x2';
    case MapRoundsHandicap = 'map_rounds_handicap';
    case MapTotalRounds = 'map_total_rounds';
    case RaceToRounds = 'race_to_rounds';

    // One round of the map being played (CS2, Valorant).
    case RoundWinner = 'round_winner';
    case PistolRound = 'pistol_round';
    case BombPlanted = 'bomb_planted';
    case Ace = 'ace';

    // One half of the map being played (CS2, Valorant).
    case Half1x2 = 'half_1x2';
    case HalfHandicap = 'half_handicap';
}
