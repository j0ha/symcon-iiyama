<?php

declare(strict_types=1);

class IIyamaDisplay extends IPSModule
{
    // Parent (Client Socket) DataID for SendDataToParent
    private const PARENT_TX_DATAID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    // Packet framing
    private const PKT_HEADER_TX     = 0xA6;
    private const PKT_HEADER_RX     = 0x21;
    private const PKT_CATEGORY      = 0x00;
    private const PKT_CODE0         = 0x00;
    private const PKT_CODE1         = 0x00;
    private const PKT_DATA_CONTROL  = 0x01;

    // Command codes — Get
    private const CMD_POWER_GET           = 0x19;
    private const CMD_IR_LOCK_GET         = 0x1D;
    private const CMD_KEYPAD_LOCK_GET     = 0x1B;
    private const CMD_COLD_START_GET      = 0xA4;
    private const CMD_PLATFORM_LABEL_GET  = 0xA2;
    private const CMD_MODEL_INFO_GET      = 0xA1;
    private const CMD_CURRENT_SOURCE_GET  = 0xAD;
    private const CMD_VIDEO_PARAMS_GET    = 0x33;
    private const CMD_COLOR_TEMP_GET      = 0x35;
    private const CMD_COLOR_PARAMS_GET    = 0x37;
    private const CMD_PICTURE_FORMAT_GET  = 0x3B;
    private const CMD_VOLUME_GET          = 0x45;
    private const CMD_MISC_INFO_GET       = 0x0F;
    private const CMD_SERIAL_CODE_GET     = 0x15;
    private const CMD_SCHEDULING_GET      = 0x5B;
    private const CMD_LANGUAGE_GET        = 0xC0;
    private const CMD_PIXEL_SHIFT_GET     = 0xB1;

    // Command codes — Set
    private const CMD_POWER_SET           = 0x18;
    private const CMD_COLD_START_SET      = 0xA3;
    private const CMD_IR_LOCK_SET         = 0x1C;
    private const CMD_KEYPAD_LOCK_SET     = 0x1A;
    private const CMD_INPUT_SOURCE_SET    = 0xAC;
    private const CMD_VIDEO_PARAMS_SET    = 0x32;
    private const CMD_COLOR_TEMP_SET      = 0x34;
    private const CMD_COLOR_PARAMS_SET    = 0x36;
    private const CMD_PICTURE_FORMAT_SET  = 0x3A;
    private const CMD_VOLUME_SET          = 0x44;
    private const CMD_VOLUME_LIMITS_SET   = 0xB8;
    private const CMD_AUDIO_PARAMS_SET    = 0x42;
    private const CMD_VGA_AUTO_ADJUST     = 0x70;
    private const CMD_PIXEL_SHIFT_SET     = 0xB2;
    private const CMD_SCHEDULING_SET      = 0x5A;
    private const CMD_LANGUAGE_SET        = 0xC1;

    // Reply opcodes (DATA[0])
    private const REP_ACK             = 0x00;
    private const REP_POWER           = 0x19;
    private const REP_KEYPAD_LOCK     = 0x1B;
    private const REP_IR_LOCK         = 0x1D;
    private const REP_MODEL_INFO      = 0xA1;
    private const REP_PLATFORM_LABEL  = 0xA2;
    private const REP_COLD_START      = 0xA4;
    private const REP_CURRENT_SOURCE  = 0xAD;
    private const REP_VIDEO_PARAMS    = 0x33;
    private const REP_COLOR_TEMP      = 0x35;
    private const REP_COLOR_PARAMS    = 0x37;
    private const REP_PICTURE_FORMAT  = 0x3B;
    private const REP_VOLUME          = 0x45;
    private const REP_MISC_INFO       = 0x0F;
    private const REP_SERIAL_CODE     = 0x15;
    private const REP_SCHEDULING      = 0x5B;
    private const REP_PIXEL_SHIFT     = 0xB1;

    // Status codes
    private const STATUS_ACTIVE         = 102;
    private const STATUS_PARENT_MISSING = 201;
    private const STATUS_BAD_MONITOR_ID = 202;

    private const SOURCE_MAP = [
        0x05 => 'VGA',
        0x06 => 'HDMI 2',
        0x0A => 'DisplayPort 1',
        0x0B => 'Card OPS',
        0x0D => 'HDMI 1',
        0x0E => 'DVI-D',
        0x0F => 'HDMI 3',
        0x10 => 'Browser',
        0x13 => 'Internal Storage',
        0x16 => 'Media Player',
        0x17 => 'PDF Player',
        0x18 => 'Custom',
        0x19 => 'HDMI 4'
    ];

    public function Create()
    {
        parent::Create();

        // Connect to a Client Socket parent (non-fatal; user may attach manually).
        @$this->ConnectParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');

        // Properties
        $this->RegisterPropertyInteger('MonitorID', 1);
        $this->RegisterPropertyInteger('PollingInterval', 30);

        // Receive buffer (binary, accumulated between ReceiveData calls)
        $this->RegisterAttributeString('RxBuffer', '');

        // Variable profiles
        $this->RegisterCustomProfiles();

        // Read-only status variables
        $this->RegisterVariableBoolean('PowerState', $this->Translate('Power State'), '~Switch', 10);
        $this->RegisterVariableString('CurrentSource', $this->Translate('Current Source'), '', 20);
        $this->RegisterVariableInteger('OperatingHours', $this->Translate('Operating Hours'), 'IIY.Hours', 30);
        $this->RegisterVariableString('ModelNumber', $this->Translate('Model Number'), '', 40);
        $this->RegisterVariableString('FirmwareVersion', $this->Translate('Firmware Version'), '', 50);
        $this->RegisterVariableString('BuildDate', $this->Translate('Build Date'), '', 60);
        $this->RegisterVariableString('PlatformLabel', $this->Translate('Platform Label'), '', 70);
        $this->RegisterVariableString('SerialCode', $this->Translate('Serial Code'), '', 80);

        // Power / system controls
        $this->RegisterVariableInteger('ColdStartBehavior', $this->Translate('Cold Start Behavior'), 'IIY.ColdStartBehavior', 100);
        $this->EnableAction('ColdStartBehavior');
        $this->RegisterVariableInteger('IRRemoteLock', $this->Translate('IR Remote Lock'), 'IIY.LockMode', 110);
        $this->EnableAction('IRRemoteLock');
        $this->RegisterVariableInteger('KeypadLock', $this->Translate('Keypad Lock'), 'IIY.LockMode', 120);
        $this->EnableAction('KeypadLock');

        // Video parameters
        $this->RegisterVariableInteger('Brightness', $this->Translate('Brightness'), 'IIY.Percent', 200);
        $this->EnableAction('Brightness');
        $this->RegisterVariableInteger('ColorSaturation', $this->Translate('Color Saturation'), 'IIY.Percent', 210);
        $this->EnableAction('ColorSaturation');
        $this->RegisterVariableInteger('Contrast', $this->Translate('Contrast'), 'IIY.Percent', 220);
        $this->EnableAction('Contrast');
        $this->RegisterVariableInteger('Sharpness', $this->Translate('Sharpness'), 'IIY.Percent', 230);
        $this->EnableAction('Sharpness');
        $this->RegisterVariableInteger('Tint', $this->Translate('Tint'), 'IIY.Percent', 240);
        $this->EnableAction('Tint');
        $this->RegisterVariableInteger('BlackLevel', $this->Translate('Black Level'), 'IIY.Percent', 250);
        $this->EnableAction('BlackLevel');
        $this->RegisterVariableInteger('Gamma', $this->Translate('Gamma'), 'IIY.Gamma', 260);
        $this->EnableAction('Gamma');
        $this->RegisterVariableInteger('ColorTemperature', $this->Translate('Color Temperature'), 'IIY.ColorTemperature', 270);
        $this->EnableAction('ColorTemperature');

        // Color RGB parameters
        $this->RegisterVariableInteger('RedGain', $this->Translate('Red Gain'), 'IIY.RGBValue', 300);
        $this->EnableAction('RedGain');
        $this->RegisterVariableInteger('GreenGain', $this->Translate('Green Gain'), 'IIY.RGBValue', 310);
        $this->EnableAction('GreenGain');
        $this->RegisterVariableInteger('BlueGain', $this->Translate('Blue Gain'), 'IIY.RGBValue', 320);
        $this->EnableAction('BlueGain');
        $this->RegisterVariableInteger('RedOffset', $this->Translate('Red Offset'), 'IIY.RGBValue', 330);
        $this->EnableAction('RedOffset');
        $this->RegisterVariableInteger('GreenOffset', $this->Translate('Green Offset'), 'IIY.RGBValue', 340);
        $this->EnableAction('GreenOffset');
        $this->RegisterVariableInteger('BlueOffset', $this->Translate('Blue Offset'), 'IIY.RGBValue', 350);
        $this->EnableAction('BlueOffset');

        // Picture format
        $this->RegisterVariableInteger('PictureFormat', $this->Translate('Picture Format'), 'IIY.PictureFormat', 400);
        $this->EnableAction('PictureFormat');

        // Pixel shift
        $this->RegisterVariableInteger('PixelShift', $this->Translate('Pixel Shift'), 'IIY.PixelShift', 410);
        $this->EnableAction('PixelShift');

        // Polling timer (interval set in ApplyChanges)
        $this->RegisterTimer('StatusPolling', 0, 'IIY_PollStatus($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        // Only remove our profiles when no other instances of this module exist.
        $instances = IPS_GetInstanceListByModuleID('{37778045-A085-5261-E2AE-4D76F782EB3D}');
        if (count($instances) <= 1) {
            foreach ([
                'IIY.ColdStartBehavior', 'IIY.LockMode', 'IIY.Gamma',
                'IIY.ColorTemperature', 'IIY.PictureFormat', 'IIY.PixelShift',
                'IIY.Percent', 'IIY.RGBValue', 'IIY.Hours'
            ] as $profile) {
                if (IPS_VariableProfileExists($profile)) {
                    IPS_DeleteVariableProfile($profile);
                }
            }
        }
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $monitorId = $this->ReadPropertyInteger('MonitorID');
        if ($monitorId < 0 || $monitorId > 255) {
            $this->SetStatus(self::STATUS_BAD_MONITOR_ID);
            return;
        }

        $this->WriteAttributeString('RxBuffer', '');

        $interval = $this->ReadPropertyInteger('PollingInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('StatusPolling', $interval * 1000);
        } else {
            $this->SetTimerInterval('StatusPolling', 0);
        }

        if ($this->HasActiveParent()) {
            $this->SetStatus(self::STATUS_ACTIVE);
        } else {
            $this->SetStatus(self::STATUS_PARENT_MISSING);
        }
    }

    // ---------------------------------------------------------------
    // Public API (callable via IIY_*)
    // ---------------------------------------------------------------

    /** Trigger a full status poll. */
    public function PollStatus(): void
    {
        $this->SendPacket([self::CMD_POWER_GET]);
        $this->SendPacket([self::CMD_CURRENT_SOURCE_GET]);
        $this->SendPacket([self::CMD_VIDEO_PARAMS_GET]);
        $this->SendPacket([self::CMD_COLOR_TEMP_GET]);
        $this->SendPacket([self::CMD_COLOR_PARAMS_GET]);
        $this->SendPacket([self::CMD_PICTURE_FORMAT_GET]);
        $this->SendPacket([self::CMD_VOLUME_GET]);
        $this->SendPacket([self::CMD_MISC_INFO_GET, 0x02]);
        $this->SendPacket([self::CMD_PIXEL_SHIFT_GET]);
        $this->SendPacket([self::CMD_IR_LOCK_GET]);
        $this->SendPacket([self::CMD_KEYPAD_LOCK_GET]);
        $this->SendPacket([self::CMD_COLD_START_GET]);
    }

    /** Fetch model, firmware, serial and platform info. */
    public function FetchDeviceInfo(): void
    {
        $this->SendPacket([self::CMD_MODEL_INFO_GET, 0x00]); // Model number
        $this->SendPacket([self::CMD_MODEL_INFO_GET, 0x01]); // FW version
        $this->SendPacket([self::CMD_MODEL_INFO_GET, 0x02]); // Build date
        $this->SendPacket([self::CMD_PLATFORM_LABEL_GET, 0x00]);
        $this->SendPacket([self::CMD_PLATFORM_LABEL_GET, 0x01]);
        $this->SendPacket([self::CMD_SERIAL_CODE_GET]);
    }

    /** Power off the display. Power-on requires Wake-on-LAN, not implemented here. */
    public function PowerOff(): bool
    {
        return $this->SendPacket([self::CMD_POWER_SET, 0x01]);
    }

    /** Switch input source by raw protocol code. */
    public function SetInputSource(int $sourceCode): bool
    {
        $ok = $this->SendPacket([self::CMD_INPUT_SOURCE_SET, $sourceCode & 0xFF, 0x00, 0x00, 0x00]);
        if ($ok) {
            $this->SendPacket([self::CMD_CURRENT_SOURCE_GET]);
        }
        return $ok;
    }

    /** Set speaker / line-out volume (0-100 each). */
    public function SetVolume(int $volume, int $audioOut): bool
    {
        $ok = $this->SendPacket([
            self::CMD_VOLUME_SET,
            max(0, min(100, $volume)),
            max(0, min(100, $audioOut))
        ]);
        if ($ok) {
            $this->SendPacket([self::CMD_VOLUME_GET]);
        }
        return $ok;
    }

    /** Trigger VGA Auto Adjust (only meaningful on a VGA source). */
    public function TriggerVGAAutoAdjust(): bool
    {
        return $this->SendPacket([self::CMD_VGA_AUTO_ADJUST, 0x40, 0x00]);
    }

    // ---------------------------------------------------------------
    // RequestAction
    // ---------------------------------------------------------------

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ColdStartBehavior':
                $this->SendPacket([self::CMD_COLD_START_SET, (int) $Value & 0xFF]);
                $this->SendPacket([self::CMD_COLD_START_GET]);
                break;

            case 'IRRemoteLock':
                $this->SendPacket([self::CMD_IR_LOCK_SET, (int) $Value & 0xFF]);
                $this->SendPacket([self::CMD_IR_LOCK_GET]);
                break;

            case 'KeypadLock':
                $this->SendPacket([self::CMD_KEYPAD_LOCK_SET, (int) $Value & 0xFF]);
                $this->SendPacket([self::CMD_KEYPAD_LOCK_GET]);
                break;

            case 'Brightness':
            case 'ColorSaturation':
            case 'Contrast':
            case 'Sharpness':
            case 'Tint':
            case 'BlackLevel':
            case 'Gamma':
                $this->SetValue($Ident, (int) $Value);
                $this->SendVideoParameters();
                $this->SendPacket([self::CMD_VIDEO_PARAMS_GET]);
                break;

            case 'ColorTemperature':
                $this->SendPacket([self::CMD_COLOR_TEMP_SET, (int) $Value & 0xFF]);
                $this->SendPacket([self::CMD_COLOR_TEMP_GET]);
                break;

            case 'RedGain':
            case 'GreenGain':
            case 'BlueGain':
            case 'RedOffset':
            case 'GreenOffset':
            case 'BlueOffset':
                $this->SetValue($Ident, (int) $Value);
                $this->SendColorParameters();
                $this->SendPacket([self::CMD_COLOR_PARAMS_GET]);
                break;

            case 'PictureFormat':
                $this->SendPacket([self::CMD_PICTURE_FORMAT_SET, (int) $Value & 0xFF]);
                $this->SendPacket([self::CMD_PICTURE_FORMAT_GET]);
                break;

            case 'PixelShift':
                $this->SendPacket([self::CMD_PIXEL_SHIFT_SET, (int) $Value & 0xFF]);
                $this->SendPacket([self::CMD_PIXEL_SHIFT_GET]);
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    // ---------------------------------------------------------------
    // Compound packet senders
    // ---------------------------------------------------------------

    private function SendVideoParameters(): bool
    {
        return $this->SendPacket([
            self::CMD_VIDEO_PARAMS_SET,
            $this->ClampByte((int) $this->GetValue('Brightness'),     0, 100),
            $this->ClampByte((int) $this->GetValue('ColorSaturation'),0, 100),
            $this->ClampByte((int) $this->GetValue('Contrast'),       0, 100),
            $this->ClampByte((int) $this->GetValue('Sharpness'),      0, 100),
            $this->ClampByte((int) $this->GetValue('Tint'),           0, 100),
            $this->ClampByte((int) $this->GetValue('BlackLevel'),     0, 100),
            $this->ClampByte((int) $this->GetValue('Gamma'),          1, 5)
        ]);
    }

    private function SendColorParameters(): bool
    {
        return $this->SendPacket([
            self::CMD_COLOR_PARAMS_SET,
            $this->ClampByte((int) $this->GetValue('RedGain'),     0, 255),
            $this->ClampByte((int) $this->GetValue('GreenGain'),   0, 255),
            $this->ClampByte((int) $this->GetValue('BlueGain'),    0, 255),
            $this->ClampByte((int) $this->GetValue('RedOffset'),   0, 255),
            $this->ClampByte((int) $this->GetValue('GreenOffset'), 0, 255),
            $this->ClampByte((int) $this->GetValue('BlueOffset'),  0, 255)
        ]);
    }

    private function ClampByte(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            $value = $min;
        }
        if ($value > $max) {
            $value = $max;
        }
        return $value & 0xFF;
    }

    // ---------------------------------------------------------------
    // Packet builder / sender
    // ---------------------------------------------------------------

    /**
     * Build a SICP packet for the given monitor ID and DATA payload.
     * Frame: [0xA6, MonID, 0x00, 0x00, 0x00, LEN, 0x01, DATA..., CHK]
     * LEN = count(DATA) + 3 (covers LEN + DataControl + DATA + CHK).
     */
    private function BuildPacket(int $monitorId, array $data): string
    {
        $length = count($data) + 3;
        $bytes = [
            self::PKT_HEADER_TX,
            $monitorId & 0xFF,
            self::PKT_CATEGORY,
            self::PKT_CODE0,
            self::PKT_CODE1,
            $length & 0xFF,
            self::PKT_DATA_CONTROL
        ];
        foreach ($data as $b) {
            $bytes[] = ((int) $b) & 0xFF;
        }
        $checksum = 0;
        foreach ($bytes as $b) {
            $checksum ^= $b;
        }
        $bytes[] = $checksum & 0xFF;
        return pack('C*', ...$bytes);
    }

    /**
     * Send a DATA payload to the configured monitor ID via the parent Client Socket.
     */
    private function SendPacket(array $data): bool
    {
        if (!$this->HasActiveParent()) {
            $this->LogMessage($this->Translate('Parent Client Socket is not connected; packet dropped.'), KL_WARNING);
            $this->SetStatus(self::STATUS_PARENT_MISSING);
            return false;
        }

        $monitorId = $this->ReadPropertyInteger('MonitorID');
        $binary = $this->BuildPacket($monitorId, $data);

        $payload = [
            'DataID' => self::PARENT_TX_DATAID,
            'Buffer' => utf8_encode($binary)
        ];

        $result = @$this->SendDataToParent(json_encode($payload));
        if ($result === false) {
            $this->LogMessage('SendDataToParent returned false for cmd 0x' . strtoupper(dechex((int) $data[0])), KL_ERROR);
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------
    // ReceiveData
    // ---------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $msg = json_decode($JSONString, true);
        if (!is_array($msg) || !isset($msg['Buffer'])) {
            return '';
        }

        $chunk = utf8_decode($msg['Buffer']);
        $buffer = $this->ReadAttributeString('RxBuffer') . $chunk;

        // Try to consume full frames from the buffer.
        while (true) {
            $len = strlen($buffer);
            if ($len < 8) {
                break;
            }
            // Resync to header byte.
            $headerPos = -1;
            for ($i = 0; $i < $len; $i++) {
                if (ord($buffer[$i]) === self::PKT_HEADER_RX) {
                    $headerPos = $i;
                    break;
                }
            }
            if ($headerPos < 0) {
                $buffer = '';
                break;
            }
            if ($headerPos > 0) {
                $buffer = substr($buffer, $headerPos);
                $len = strlen($buffer);
                if ($len < 8) {
                    break;
                }
            }

            // Frame layout: [HDR(0), MonID(1), Cat(2), Page(3), LEN(4), DC(5), DATA..., CHK]
            // RX LEN covers DC + DATA + CHK only (does not include itself).
            // Total frame = 4 fixed header bytes + 1 LEN byte + LEN value = LEN + 5.
            $lenField = ord($buffer[4]);
            $totalSize = $lenField + 5;
            if ($len < $totalSize) {
                break; // wait for more data
            }

            $frame = substr($buffer, 0, $totalSize);
            $buffer = substr($buffer, $totalSize);

            $this->ProcessFrame($frame);
        }

        $this->WriteAttributeString('RxBuffer', $buffer);
        return '';
    }

    private function ProcessFrame(string $frame): void
    {
        $bytes = array_values(unpack('C*', $frame));
        $count = count($bytes);
        if ($count < 7) {
            $this->LogMessage('Received frame too short (' . $count . ' bytes)', KL_WARNING);
            return;
        }

        if ($bytes[0] !== self::PKT_HEADER_RX) {
            $this->LogMessage(sprintf('Bad reply header: 0x%02X', $bytes[0]), KL_WARNING);
            return;
        }

        $monitorId = $bytes[1];
        $configuredId = $this->ReadPropertyInteger('MonitorID');
        if ($monitorId !== $configuredId) {
            // Frame is for a different display sharing this socket — ignore silently.
            return;
        }

        // XOR checksum over all bytes except the last one.
        $checksum = 0;
        for ($i = 0; $i < $count - 1; $i++) {
            $checksum ^= $bytes[$i];
        }
        if ($checksum !== $bytes[$count - 1]) {
            $this->LogMessage(sprintf('Checksum mismatch: calc 0x%02X, recv 0x%02X', $checksum, $bytes[$count - 1]), KL_ERROR);
            return;
        }

        // Frame layout: [HDR, MonID, Cat, Page, LEN, DataControl, DATA[0]..DATA[N], CHK]
        $data = array_slice($bytes, 6, -1);
        if (empty($data)) {
            return;
        }

        $this->ParseReport($data);
    }

    private function ParseReport(array $data): void
    {
        $opcode = $data[0];
        switch ($opcode) {
            case self::REP_ACK:             $this->ParseACK($data);             break;
            case self::REP_POWER:           $this->ParsePowerState($data);      break;
            case self::REP_KEYPAD_LOCK:     $this->ParseKeypadLock($data);      break;
            case self::REP_IR_LOCK:         $this->ParseIRRemoteLock($data);    break;
            case self::REP_MODEL_INFO:      $this->ParseModelInfo($data);       break;
            case self::REP_PLATFORM_LABEL:  $this->ParsePlatformLabel($data);   break;
            case self::REP_COLD_START:      $this->ParseColdStart($data);       break;
            case self::REP_CURRENT_SOURCE:  $this->ParseCurrentSource($data);   break;
            case self::REP_VIDEO_PARAMS:    $this->ParseVideoParams($data);     break;
            case self::REP_COLOR_TEMP:      $this->ParseColorTemperature($data);break;
            case self::REP_COLOR_PARAMS:    $this->ParseColorParams($data);     break;
            case self::REP_PICTURE_FORMAT:  $this->ParsePictureFormat($data);   break;
            case self::REP_VOLUME:          $this->ParseVolume($data);          break;
            case self::REP_MISC_INFO:       $this->ParseMiscInfo($data);        break;
            case self::REP_SERIAL_CODE:     $this->ParseSerialCode($data);      break;
            case self::REP_SCHEDULING:      $this->ParseScheduling($data);      break;
            case self::REP_PIXEL_SHIFT:     $this->ParsePixelShift($data);      break;
            default:
                $this->LogMessage(sprintf('Unknown reply opcode 0x%02X', $opcode), KL_WARNING);
        }
    }

    // ---------------------------------------------------------------
    // Parse handlers
    // ---------------------------------------------------------------

    private function ParseACK(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $status = $data[1];
        $labels = [
            0x00 => 'Completed',
            0x01 => 'Limit Over (above upper limit)',
            0x02 => 'Limit Over (below lower limit)',
            0x03 => 'Command cancelled',
            0x04 => 'Parse Error (checksum/format)'
        ];
        $label = $labels[$status] ?? sprintf('Unknown status 0x%02X', $status);
        if ($status === 0x00) {
            $this->LogMessage('ACK: ' . $label, KL_NOTIFY);
        } else {
            $this->LogMessage('NACK: ' . $label, KL_ERROR);
        }
    }

    private function ParsePowerState(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        // 0x01 = Off, 0x02 = On
        $this->SetValue('PowerState', $data[1] === 0x02);
    }

    private function ParseKeypadLock(array $data): void
    {
        if (count($data) >= 2) {
            $this->SetValue('KeypadLock', $data[1]);
        }
    }

    private function ParseIRRemoteLock(array $data): void
    {
        if (count($data) >= 2) {
            $this->SetValue('IRRemoteLock', $data[1]);
        }
    }

    private function ParseModelInfo(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $kind = $data[1];
        $payload = $this->BytesToAsciiString(array_slice($data, 2));
        switch ($kind) {
            case 0x00: $this->SetValue('ModelNumber', $payload); break;
            case 0x01: $this->SetValue('FirmwareVersion', $payload); break;
            case 0x02: $this->SetValue('BuildDate', $payload); break;
        }
    }

    private function ParsePlatformLabel(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        // Both 0x00 (OTSC version) and 0x01 (label) end up in PlatformLabel.
        $payload = $this->BytesToAsciiString(array_slice($data, 2));
        if ($payload !== '') {
            $existing = $this->GetValue('PlatformLabel');
            if ($data[1] === 0x01 || $existing === '') {
                $this->SetValue('PlatformLabel', $payload);
            }
        }
    }

    private function ParseColdStart(array $data): void
    {
        if (count($data) >= 2) {
            $this->SetValue('ColdStartBehavior', $data[1]);
        }
    }

    private function ParseCurrentSource(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $code = $data[1];
        $name = self::SOURCE_MAP[$code] ?? sprintf('Unknown (0x%02X)', $code);
        $this->SetValue('CurrentSource', $name);
    }

    private function ParseVideoParams(array $data): void
    {
        // [0x33, brightness, color, contrast, sharpness, tint, blackLevel, gamma]
        if (count($data) < 8) {
            return;
        }
        $this->SetValue('Brightness',      $data[1]);
        $this->SetValue('ColorSaturation', $data[2]);
        $this->SetValue('Contrast',        $data[3]);
        $this->SetValue('Sharpness',       $data[4]);
        $this->SetValue('Tint',            $data[5]);
        $this->SetValue('BlackLevel',      $data[6]);
        $this->SetValue('Gamma',           $data[7]);
    }

    private function ParseColorTemperature(array $data): void
    {
        if (count($data) >= 2) {
            $this->SetValue('ColorTemperature', $data[1]);
        }
    }

    private function ParseColorParams(array $data): void
    {
        if (count($data) < 7) {
            return;
        }
        $this->SetValue('RedGain',     $data[1]);
        $this->SetValue('GreenGain',   $data[2]);
        $this->SetValue('BlueGain',    $data[3]);
        $this->SetValue('RedOffset',   $data[4]);
        $this->SetValue('GreenOffset', $data[5]);
        $this->SetValue('BlueOffset',  $data[6]);
    }

    private function ParsePictureFormat(array $data): void
    {
        if (count($data) >= 2) {
            $this->SetValue('PictureFormat', $data[1]);
        }
    }

    private function ParseVolume(array $data): void
    {
        // No volume variables in this version; log only.
        if (count($data) >= 3) {
            $this->LogMessage(sprintf('Volume reply — speaker=%d, audio_out=%d', $data[1], $data[2]), KL_NOTIFY);
        }
    }

    private function ParseMiscInfo(array $data): void
    {
        // Operating Hours: [0x0F, 0x02, hoursH, hoursL, minutes]
        if (count($data) >= 4 && $data[1] === 0x02) {
            $hours = ($data[2] << 8) | $data[3];
            $this->SetValue('OperatingHours', $hours);
        }
    }

    private function ParseSerialCode(array $data): void
    {
        $payload = $this->BytesToAsciiString(array_slice($data, 1));
        if ($payload !== '') {
            $this->SetValue('SerialCode', $payload);
        }
    }

    private function ParseScheduling(array $data): void
    {
        $this->LogMessage('Scheduling reply received (not mapped to variables): ' . bin2hex(pack('C*', ...$data)), KL_NOTIFY);
    }

    private function ParsePixelShift(array $data): void
    {
        if (count($data) >= 2) {
            $this->SetValue('PixelShift', $data[1]);
        }
    }

    private function BytesToAsciiString(array $bytes): string
    {
        $out = '';
        foreach ($bytes as $b) {
            if ($b >= 0x20 && $b < 0x7F) {
                $out .= chr($b);
            }
        }
        return trim($out);
    }

    // ---------------------------------------------------------------
    // Variable profile registration
    // ---------------------------------------------------------------

    private function RegisterCustomProfiles(): void
    {
        $this->EnsureIntegerProfile('IIY.ColdStartBehavior', '', '', '', [
            [0, 'Power Off',    '', -1],
            [1, 'Forced On',    '', -1],
            [2, 'Last Status',  '', -1]
        ]);

        $lockOptions = [
            [1, 'Unlock all',              '', -1],
            [2, 'Lock all',                '', -1],
            [3, 'Lock all but Power',      '', -1],
            [4, 'Lock all but Volume',     '', -1],
            [7, 'Lock all but Power & Volume', '', -1]
        ];
        $this->EnsureIntegerProfile('IIY.LockMode', '', '', '', $lockOptions);

        $this->EnsureIntegerProfile('IIY.Gamma', '', '', '', [
            [1, 'Native',  '', -1],
            [2, 'S-gamma', '', -1],
            [3, '2.2',     '', -1],
            [4, '2.4',     '', -1],
            [5, 'DICOM',   '', -1]
        ]);

        $this->EnsureIntegerProfile('IIY.ColorTemperature', '', '', '', [
            [0,  'User 1',  '', -1],
            [1,  'Native',  '', -1],
            [3,  '10000K',  '', -1],
            [4,  '9300K',   '', -1],
            [5,  '7500K',   '', -1],
            [6,  '6500K',   '', -1],
            [9,  '5000K',   '', -1],
            [10, '4000K',   '', -1],
            [13, '3000K',   '', -1],
            [18, 'User 2',  '', -1]
        ]);

        $this->EnsureIntegerProfile('IIY.PictureFormat', '', '', '', [
            [0, 'Normal 4:3', '', -1],
            [1, 'Custom',     '', -1],
            [2, 'Real 1:1',   '', -1],
            [3, 'Full',       '', -1],
            [4, '21:9',       '', -1],
            [5, 'Dynamic',    '', -1],
            [6, '16:9',       '', -1]
        ]);

        $pixelShift = [[0, 'Off', '', -1]];
        for ($i = 1; $i <= 90; $i++) {
            $pixelShift[] = [$i, sprintf('%ds', $i * 10), '', -1];
        }
        $pixelShift[] = [91, 'Auto', '', -1];
        $this->EnsureIntegerProfile('IIY.PixelShift', '', '', '', $pixelShift);

        // Range profiles (no associations)
        $this->EnsureRangeProfile('IIY.Percent',  '', '', ' %', 0, 100, 1);
        $this->EnsureRangeProfile('IIY.RGBValue', '', '', '',    0, 255, 1);
        $this->EnsureRangeProfile('IIY.Hours',    '', '', ' h',  0, 0,   1);
    }

    private function EnsureIntegerProfile(string $name, string $icon, string $prefix, string $suffix, array $associations): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1); // 1 = Integer
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, 0, 0, 0);

        // Refresh associations: clear existing first.
        $existing = IPS_GetVariableProfile($name)['Associations'] ?? [];
        foreach ($existing as $assoc) {
            @IPS_SetVariableProfileAssociation($name, $assoc['Value'], '', '', -1);
        }
        foreach ($associations as $a) {
            IPS_SetVariableProfileAssociation($name, $a[0], $a[1], $a[2], $a[3]);
        }
    }

    private function EnsureRangeProfile(string $name, string $icon, string $prefix, string $suffix, int $min, int $max, int $step): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
    }
}
