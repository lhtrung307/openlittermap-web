<?php

namespace App\Http\Controllers\Teams;

use App\Exports\CreateCSVExport;
use App\Jobs\EmailUserExportCompleted;
use App\Models\Photo;
use App\Models\Teams\Team;
use App\Models\Teams\TeamType;
use App\Traits\FilterTeamMembersTrait;

use App\Events\TeamCreated;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\Controller;

class TeamsController extends Controller
{
    use FilterTeamMembersTrait;

    /**
     * Change the users currently active team
     */
    public function active (Request $request)
    {
        $user = Auth::user();

        if (Team::find($request->team_id))
        {
            foreach ($user->teams as $team)
            {
                if ($team->id === $request->team_id)
                {
                    $user->active_team = $request->team_id;
                    $user->save();

                    return ['success' => true];
                }
            }
        }

        return ['success' => false];
    }

    /**
     * The user wants to create a new team
     */
    public function create (Request $request)
    {
        $request->validate([
            'name' => 'required|min:3|max:100|unique:teams',
            'identifier' => 'required|min:3|max:15|unique:teams',
            'teamType' => 'required'
        ]);

        $user = Auth::user();

        if ($user->remaining_teams === 0) return ['success' => false, 'msg' => 'max-created'];

        $team = Team::create([
            'created_by' => $user->id,
            'name' => $request->name,
            'type_id' => $request->teamType,
            'leader' => $user->id,
            'identifier' => $request->identifier
        ]);

        // Broadcast live event to the global map
        event (new TeamCreated($team->name));

        // Have the user join this team
        $user->teams()->attach($team);

        $user->active_team = $team->id;
        $user->remaining_teams--;
        $user->save();

        return ['success' => true, 'team' => $team];
    }

    /**
     * The user wants to download data from a specific team
     */
    public function download (Request $request)
    {
        $email = auth()->user()->email;

        $x     = new \DateTime();
        $date  = $x->format('Y-m-d');
        $date  = explode('-', $date);
        $year  = $date[0];
        $month = $date[1];
        $day   = $date[2];
        $unix  = now()->timestamp;

        $path = $year.'/'.$month.'/'.$day.'/'.$unix.'/';  // 2020/10/25/unix/

        $path .= '_Team_OpenLitterMap.csv';

        /* Dispatch job to create CSV file for export */
        (new CreateCSVExport($request->type, null, $request->team_id))
            ->queue($path, 's3', null, ['visibility' => 'public'])
            ->chain([
                // These jobs are executed when above is finished.
                new EmailUserExportCompleted($email, $path)
                // new ....job
            ]);

        return ['success' => true];
    }

    /**
     * The user wants to join a new team
     */
    public function join (Request $request)
    {
        $request->validate([
            'identifier' => 'required|min:3|max:100'
        ]);

        $user = Auth::user();

        if ($team = Team::where('identifier', $request->identifier)->first())
        {
            // Check the user is not already in the team
            foreach ($user->teams as $t)
            {
                if ($team->id === $t->id) return ['success' => false, 'msg' => 'already-joined'];
            }

            // Have the user join this team
            $user->teams()->attach($team);

            if (is_null($user->active_team))
            {
                $user->active_team = $team->id;
                $user->save();
            }

            $team->members++;
            $team->save();

            return ['success' => true, 'team_id' => $team->id];
        }

        return ['success' => false, 'msg' => 'not-found'];
    }

    /**
     * Array of teams the user has joined
     */
    public function joined ()
    {
        return Auth::user()->teams;
    }

    /**
     * Get paginated members for a team_id
     *
     * We need to check the privacy settings for each user on the team,
     * and only load the columns (show_name_leaderboard) that each user has allowed.
     */
    public function members ()
    {
        $query = $this->filterTeamMembers(request()->team_id);

        $total_members = $query->users->count(); // members?

        $result = $query->users()
            ->withPivot('total_photos', 'total_litter', 'updated_at', 'show_name_leaderboards', 'show_username_leaderboards')
            ->orderBy('pivot_total_litter', 'desc')
            ->simplePaginate(10, [
                // include these fields
                'users.id',
                'users.name', // todo - only load this if team_user.show_name is true
                'users.username', // todo - only load this if team_user.show_username is true
                'users.active_team',
                'users.updated_at', // todo add users.last_uploaded
                'total_photos'
            ]);

        // We need to filter out name/username based on the settings
        // For now, just remove them manually with a loop.
        // We should figure out how to do this in the query
        // https://stackoverflow.com/questions/65371551/filter-simplepaginate-column-select-by-pivot-table
        foreach ($result as $r)
        {
            if (! $r->pivot->show_name_leaderboards) $r->name = null;
            if (! $r->pivot->show_username_leaderboards) $r->username = null;
        }

        return [
            'total_members' => $total_members,
            'result' => $result
        ];
    }

    /**
     * Return the types of available teams
     */
    public function types ()
    {
        return TeamType::select('id', 'team')->get();
    }
}
