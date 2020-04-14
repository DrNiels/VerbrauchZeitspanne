<?php

declare(strict_types=1);

define('LOD_DATE', 0);
define('LOD_TIME', 1);
define('LOD_DATETIME', 2);
include_once __DIR__ . '/timetest.php';
    class VerbrauchZeitspanne extends IPSModule
    {
        //Using an own time() function in order to use custom time while testing
        use TestTime;

        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->RegisterPropertyInteger('SourceVariable', 0);
            $this->RegisterPropertyInteger('LevelOfDetail', 0);
        }

        public function ApplyChanges()
        {

            //Never delete this line!
            parent::ApplyChanges();

            //Get profile
            $timeProfile = '';
            $levelOfDetail = $this->ReadPropertyInteger('LevelOfDetail');
            switch ($levelOfDetail) {
                case LOD_DATE:
                    $timeProfile = '~UnixTimestampDate';
                    break;
                case LOD_TIME:
                    $timeProfile = '~UnixTimestampTime';
                    break;
                case LOD_DATETIME:
                    $timeProfile = '~UnixTimestamp';
                    break;
            }

            //Create variables
            $this->RegisterVariableInteger('StartDate', 'Start-Datum', $timeProfile, 1);
            $this->EnableAction('StartDate');

            if (GetValue($this->GetIDForIdent('StartDate')) == 0) {
                SetValue($this->GetIDForIdent('StartDate'), strtotime(date('d-m-Y H:i:00', $this->getTime())));
            }

            $this->RegisterVariableInteger('EndDate', 'End-Datum', $timeProfile, 2);
            $this->EnableAction('EndDate');

            if (GetValue($this->GetIDForIdent('EndDate')) == 0) {
                SetValue($this->GetIDForIdent('EndDate'), strtotime(date('d-m-Y H:i:00', $this->getTime())));
            }

            $sourceVariable = $this->ReadPropertyInteger('SourceVariable');
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            if ($sourceVariable > 0 && IPS_VariableExists($sourceVariable)) {
                if (AC_GetLoggingStatus($archiveID, $sourceVariable) && (AC_GetAggregationType($archiveID, $sourceVariable) == 1 /* Counter */)) {
                    $v = IPS_GetVariable($sourceVariable);

                    $sourceProfile = '';
                    $sourceProfile = $v['VariableCustomProfile'];
                    if ($sourceProfile == '') {
                        $sourceProfile = $v['VariableProfile'];
                    }

                    switch ($v['VariableType']) {
                        case 1: /* Integer */
                            $this->RegisterVariableInteger('Usage', 'Verbrauch', $sourceProfile, 3);
                            break;

                        case 2: /* Float */
                            $this->RegisterVariableFloat('Usage', 'Verbrauch', $sourceProfile, 3);
                            break;

                        default:
                            return;
                    }

                    $this->SetStatus(102);
                } elseif (AC_GetLoggingStatus($archiveID, $sourceVariable) == false) {
                    var_dump(AC_GetLoggingStatus($archiveID, $sourceVariable));
                    $this->SetStatus(200);
                } elseif (AC_GetAggregationType($archiveID, $sourceVariable) != 1 /* Counter */) {
                    $this->SetStatus(201);
                }
            } else {
                $this->SetStatus(104);
            }

            //Add references
            foreach ($this->GetReferenceList() as $referenceID) {
                $this->UnregisterReference($referenceID);
            }
            if (IPS_VariableExists($sourceVariable)) {
                $this->RegisterReference($sourceVariable);
            }
        }

        public function RequestAction($Ident, $Value)
        {
            switch ($Ident) {
                case 'StartDate':
                case 'EndDate':
                    //Neuen Wert in die Statusvariable schreiben
                    if (date('s', $Value) != 0) {
                        SetValue($this->GetIDForIdent($Ident), strtotime(date('d-m-Y H:i:00', $Value)));
                        if ($this->ReadPropertyInteger('LevelOfDetail') != LOD_DATE) {
                            echo $this->Translate('The seconds will be ignored.');
                        }
                        break;
                    } else {
                        SetValue($this->GetIDForIdent($Ident), $Value);
                    }
                    //Berechnen
                    $this->Calculate();
                    break;
                default:
                    throw new Exception('Invalid Ident');
            }
        }

        /**
         * This function will be available automatically after the module is imported with the module control.
         * Using the custom prefix this function will be callable from PHP and JSON-RPC through:
         *
         * VIZ_Calculate($id);
         *
         */
        public function Calculate()
        {
            if ($this->GetStatus() != 102) {
                return;
            }
            $acID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $variableID = $this->ReadPropertyInteger('SourceVariable');
            $levelOfDetail = $this->ReadPropertyInteger('LevelOfDetail');
            $startDate = GetValue($this->GetIDForIdent('StartDate'));
            $endDate = GetValue($this->GetIDForIdent('EndDate'));
            //Reduce enddate if lod is not date
            if ($levelOfDetail != LOD_DATE) {
                $endDate--;
            }
            $values = [];
            $sum = 0;
            if (($startDate == $endDate) || ($startDate > $endDate)) {
                SetValue($this->GetIDForIdent('Usage'), 0);
                return;
            }
            //Set startDate/endDate for LOD_TIME to same day
            if ($levelOfDetail == LOD_TIME) {
                $startDate = strtotime(date('H:i:s', $startDate), $this->getTime());
                $endDate = strtotime(date('H:i:s', $endDate), $this->getTime());
            }
            if ($levelOfDetail == LOD_DATE) {
                $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 1 /* Day */, $startDate, strtotime(date('d-m-Y', $endDate) . ' 23:59:59'), 0));
            //Check if startDate/endDate are in the same hour
            } elseif (date('d.m.Y H', $startDate) == date('d.m.Y H', $endDate)) {
                $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 6 /* Minutes */, $startDate, $endDate, 0));
            } else {
                //FirstMinutes
                $this->SendDebug('FirstMinutsStart', date('H:i:s', $startDate), 0);
                //StartDate at H:59:59
                $firstMinutesEnd = strtotime(date('H', $startDate) . ':59:59', $startDate);
                $this->SendDebug('FirstMinutsEnd', date('H:i:s', $firstMinutesEnd), 0);
                $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 6 /* Minutes */, $startDate, $firstMinutesEnd, 0));

                //LastMinutes
                //Full hour of endDate
                $lastMinutesStart = strtotime(date('H', $endDate) . ':00:00', $endDate);
                $this->SendDebug('LastMinutsStart', date('H:i:s', $lastMinutesStart), 0);
                $this->SendDebug('LastMinutsEnd', date('H:i:s', $endDate), 0);
                $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 6 /* Minutes */, $lastMinutesStart, $endDate, 0));

                //FirstHour start/end
                $hoursStart = $firstMinutesEnd + 1;
                $hoursEnd = $lastMinutesStart - 1;
                if (date('d.m.Y', $startDate) == date('d.m.Y', $endDate)) {
                    //Hours
                    $this->SendDebug('StartHours', date('H:i:s', $hoursStart), 0);
                    $this->SendDebug('EndHours', date('H:i:s', $hoursEnd), 0);
                    $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 0 /* Hour */, $hoursStart, $hoursEnd, 0));
                } else {
                    //FirstHours
                    $this->SendDebug('FirstHoursStart', date('d.m.Y H:i:s', $hoursStart), 0);
                    //23:59:59 on startDate
                    $firstHoursEnd = strtotime('23:59:59', $startDate);
                    $this->SendDebug('FirstHoursEnd', date('d.m.Y H:i:s', $firstHoursEnd), 0);
                    $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 0 /* Hour */, $hoursStart, $firstHoursEnd, 0));

                    //LastHours
                    //00:00:00 on endDate
                    $lastHoursStart = strtotime('00:00:00', $endDate);
                    $this->SendDebug('LastHoursStart', date('d.m.Y H:i:s', $lastHoursStart), 0);
                    $this->SendDebug('LastHoursEnd', date('d.m.Y H:i:s', $hoursEnd), 0);
                    $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 0 /* Hour */, $lastHoursStart, $hoursEnd, 0));

                    //Days
                    $daysStart = $firstHoursEnd + 1;
                    $this->SendDebug('StartDays', date('d.m.Y H:i:s', $daysStart), 0);
                    $daysEnd = $lastHoursStart - 1;
                    $this->SendDebug('EndDays', date('d.m.Y H:i:s', $daysEnd), 0);
                    $values = array_merge($values, AC_GetAggregatedValues($acID, $variableID, 1 /* Day */, $daysStart, $daysEnd, 0));
                }
            }

            if ($values === false) {
                $this->SendDebug('Error', 'NoData', 0);
                return;
            }

            foreach ($values as $value) {
                $sum += $value['Avg'];
            }

            SetValue($this->GetIDForIdent('Usage'), $sum);
        }
    }