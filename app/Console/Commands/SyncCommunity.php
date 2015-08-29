<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use App\Models\Mship\Account;

class SyncCommunity extends aCommand
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'Sync:Community';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync membership data from Core to Community.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        if ($this->option('verbose')) {
            $verbose = true;
        } else {
            $verbose = false;
        }

        require_once('/var/www/community/init.php');
        require_once(IPS\ROOT_PATH . '/system/Member/Member.php');
        require_once(IPS\ROOT_PATH . '/system/Db/Db.php');

        $members = \IPS\Db::i()->select('m.member_id, m.vatsim_cid, m.name, m.email, m.member_title, p.field_12, p.field_13, p.field_14', ['core_members', 'm'])
                               ->join(['core_pfields_content', 'p'], 'm.member_id = p.member_id');

        $countTotal = $members->count();
        $countSuccess = 0;
        $countFailure = 0;

        $sso_account_id = DB::table('sso_account')->where('username', 'vuk.community')->first()->sso_account_id;
        for ($i = 0; $i < $countTotal; $i++) {
            $members->next();

            $member = $members->current();

            if (empty($member['vatsim_cid']) || !is_numeric($member['vatsim_cid'])) {
                if ($verbose) {
                    $this->output->writeln('<error>FAILURE: ' . $member['member_id'] . ' has no valid CID.</error>');
                }
                $countFailure++;
                continue;
            }

            if ($verbose) {
                $this->output->write($member['member_id'] . ' // ' . $member['vatsim_cid']);
            }

            $member_core = Account::where('account_id', $member['vatsim_cid'])->with('states', 'qualifications')->first();
            if ($member_core === NULL) {
                if ($verbose) {
                    $this->output->writeln(' // <error>FAILURE: cannot retrieve member ' . $member['member_id'] . ' from Core.</error>');
                }
                $countFailure++;
                continue;
            }

            $email = $member_core->primary_email;
            $ssoEmailAssigned = $member_core->ssoEmails->filter(function ($ssoemail) use ($sso_account_id) {
                return $ssoemail->sso_account_id == $sso_account_id;
            })->values();

            if ($ssoEmailAssigned && count($ssoEmailAssigned) > 0) {
                $email = $ssoEmailAssigned[0]->email->email;
            }

            $emailLocal = false;
            if (empty($email)) {
                $email = $member['email'];
                $emailLocal = true;
            }

            $state = $member_core->states()->where('state', '=', \App\Models\Mship\Account\State::STATE_DIVISION)->first()->state ? 'Division Member' : 'International Member';
            $state = $member_core->states()->where('state', '=', \App\Models\Mship\Account\State::STATE_VISITOR)->first()->state ? 'Visiting Member' : $state;
            $aRatingString = $member_core->qualification_atc->qualification->name_long;
            $pRatingString = $member_core->qualifications_pilot_string;

            // Check for changes
            $changeEmail = strcasecmp($member['email'], $email);
            $changeName = strcmp($member['name'], $member_core->name_first . ' ' . $member_core->name_last);
            $changeState = strcasecmp($member['member_title'], $state);
            $changeCID = strcmp($member['field_12'], $member_core->account_id);
            $changeARating = strcmp($member['field_13'], $aRatingString);
            $changePRating = strcmp($member['field_14'], $pRatingString);
            $changesPending = $changeEmail || $changeName || $changeState || $changeCID
                              || $changeARating || $changePRating;

            if ($verbose) {
                $this->output->write(' // ID: ' . $member_core->account_id);
                $this->output->write(' // Email (' . ($emailLocal ? 'local' : "latest") . "):" . $email . ($changeEmail ? "(changed)" : ""));
                $this->output->write(' // Display: ' . $member_core->name_first . " " . $member_core->name_last . ($changeName ? "(changed)" : ""));
                $this->output->write(' // State: ' . $state . ($changeState ? "(changed)" : ""));
                $this->output->write(' // ATC rating: ' . $aRatingString);
                $this->output->write(' // Pilot ratings: ' . $pRatingString);
            }

            if ($changesPending) {
                try {
                    // ActiveRecord / Member fields
                    $ips_member = \IPS\Member::load($member['member_id']);
                    $ips_member->name = $member_core->name_first . ' ' . $member_core->name_last;
                    $ips_member->email = $email;
                    $ips_member->member_title = $state;
                    $ips_member->save();

                    // Profile fields (raw update)
                    $update = [
                        'field_12' => $member_core->account_id, // VATSIM CID
                        'field_13' => $aRatingString, // Controller Rating
                        'field_14' => $pRatingString, // Pilot Ratings
                    ];
                    $updated_rows = \IPS\Db::i()->update('core_pfields_content', $update, ['member_id=?', $member['member_id']]);

                    if ($verbose) {
                        $this->output->writeln(' // Details saved successfully.');
                    }
                    $countSuccess++;
                } catch (Exception $e) {
                    $countFailure++;
                    $this->output->writeln(' // <error>FAILURE: Error saving ' . $member_core->account_id . ' details to forum.</error>' . $e->getMessage());
                }
            } elseif ($verbose) {
                $this->output->writeln(' // No changes required.');
            }
        }

        if ($verbose) {
            $this->output->writeln('Run Results:');
            $this->output->writeln('Total Accounts: '.$countTotal);
            $this->output->writeln('Successful Updates: '.$countSuccess);
            $this->output->writeln('Failed Updates: '.$countFailure);
        }
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
            //array('force-update', 'f', InputOption::VALUE_OPTIONAL, 'If specified, only this CID will be checked.', 0),
        );
    }
}
