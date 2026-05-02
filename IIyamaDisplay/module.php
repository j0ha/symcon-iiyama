<?php

declare(strict_types=1);

/**
 * IIyamaDisplay — IP-Symcon device module for iiyama ProLite LH60-series displays.
 *
 * Controls the display over LAN via the proprietary SICP binary protocol (TCP port 5000).
 * Sits on top of an IP-Symcon Client Socket I/O instance; do NOT open sockets manually.
 *
 * Power-On via LAN is NOT supported by the SICP protocol. Use Wake-on-LAN separately.
 */
class IIyamaDisplay extends IPSModule
{
    // ── Protocol constants ─────────────────────────────────────────────────────

    private const HEADER_SEND    = 0xA6;
    private const HEADER_RECV    = 0x21;
    private const DATA_CTRL      = 0x01;
    private const CATEGORY       = 0x00;
    private const CODE0          = 0x00;
    private const CODE1          = 0x00;

    // Get command codes (DATA[0] of request / DATA[0] of response)
    private const CMD_POWER_GET           = 0x19;
    private const CMD_KEYPAD_LOCK_GET     = 0x1B;
    private const CMD_IR_REMOTE_LOCK_GET  = 0x1D;
    private const CMD_COLD_START_GET      = 0xA4;
    private const CMD_PLATFORM_LABEL_GET  = 0xA2;
    private const CMD_MODEL_INFO_GET      = 0xA1;
    private const CMD_CURRENT_SOURCE_GET  = 0xAD;
    private const CMD_VIDEO_PARAMS_GET    = 0x33;
    private const CMD_COLOR_TEMP_GET      = 0x35;
    private const CMD_COLOR_PARAMS_GET    = 0x37;
    private const CMD_PICTURE_FORMAT_GET  = 0x3B;
    private const CMD_VOLUME_GET          = 0x45;
    private const CMD_OPERATING_HOURS_GET = 0x0F;
    private const CMD_SERIAL_CODE_GET     = 0x15;
    private const CMD_SCHEDULING_GET      = 0x5B;
    private const CMD_PIXEL_SHIFT_GET     = 0xB1;

    // Set command codes
    private const CMD_POWER_SET           = 0x18;
    private const CMD_KEYPAD_LOCK_SET     = 0x1A;
    private const CMD_IR_REMOTE_LOCK_SET  = 0x1C;
    private const CMD_COLD_START_SET      = 0xA3;
    private const CMD_INPUT_SOURCE_SET    = 0xAC;
    private const CMD_VIDEO_PARAMS_SET    = 0x32;
    private const CMD_COLOR_TEMP_SET      = 0x34;
    private const CMD_COLOR_PARAMS_SET    = 0x36;
    private const CMD_PICTURE_FORMAT_SET  = 0x3A;
    private const CMD_VOLUME_SET          = 0x44;
    private const CMD_AUDIO_PARAMS_SET    = 0x42;
    private const CMD_VGA_AUTO_ADJUST     = 0x70;
    private const CMD_PIXEL_SHIFT_SET     = 0xB2;

    // ACK status codes (DATA[1] when DATA[0] == 0x00)
    private const ACK_COMPLETED        = 0x00;
    private const ACK_LIMIT_OVER_HIGH  = 0x01;
    private const ACK_LIMIT_OVER_LOW   = 0x02;
    private const ACK_CANCELLED        = 0x03;
    private const ACK_PARSE_ERROR      = 0x04;

    // Input source code → human-readable label
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
        0x19 => 'HDMI 4',
    ];

    // DataID for sending data through Client Socket parent
    private const SEND_DATA_ID = '{79827379-F36E-4D09-7486-2097DF03C07F}';

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('MonitorID', 1);
        $this->RegisterPropertyInteger('PollingInterval', 30);

        $this->RegisterTimer('StatusPolling', 0, 'IIY_PollStatus($_IPS[\'TARGET\']);');

        $this->SetBuffer('ReceiveBuffer', '');

        $this->RegisterProfiles();
        $this->RegisterVariables();
    }

    public function Destroy(): void
    {
        // Only unregister profiles when this is the last instance of this module
        if (count(IPS_GetInstanceListByModuleID('{470AAC69-65EE-4FF4-AFA5-1D42D9500F31}')) <= 1) {
            $profiles = [
                'IIY.ColdStartBehavior',
                'IIY.LockMode',
                'IIY.Gamma',
                'IIY.ColorTemperature',
                'IIY.PictureFormat',
                'IIY.PixelShift',
                'IIY.Percent',
                'IIY.RGBValue',
                'IIY.Hours',
            ];
            foreach ($profiles as $profile) {
                if (IPS_VariableProfileExists($profile)) {
                    IPS_DeleteVariableProfile($profile);
                }
            }
        }

        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $interval = $this->ReadPropertyInteger('PollingInterval');
        $this->SetTimerInterval('StatusPolling', $interval > 0 ? $interval * 1000 : 0);
    }

    // ── Configuration form ─────────────────────────────────────────────────────

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'MonitorID',
                    'caption' => 'Monitor ID (1–255; 0 = broadcast, no ACK)',
                    'minimum' => 0,
                    'maximum' => 255,
                    'value'   => 1,
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'PollingInterval',
                    'caption' => 'Status polling interval in seconds (0 = disabled)',
                    'minimum' => 0,
                    'maximum' => 3600,
                    'suffix'  => ' s',
                    'value'   => 30,
                ],
                [
                    'type'    => 'Label',
                    'caption' => '⚠ Power-On via LAN is not supported by the SICP protocol. Use Wake-on-LAN (a separate IPS module or script) to turn the display on.',
                ],
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Fetch Device Info Now',
                    'onClick' => 'IIY_FetchDeviceInfo($id);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Poll Status Now',
                    'onClick' => 'IIY_PollStatus($id);',
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Connected'],
                ['code' => 104, 'icon' => 'inactive',  'caption' => 'Disconnected'],
                ['code' => 201, 'icon' => 'inactive',  'caption' => 'No parent assigned'],
            ],
        ]);
    }

    // ── Receiving data from the Client Socket ──────────────────────────────────

    public function ReceiveData(string $JSONString): void
    {
        $incoming = json_decode($JSONString, true);
        // IPS delivers binary data encoded as a UTF-8 string; decode it back to bytes.
        $raw = utf8_decode($incoming['Buffer']);

        $buffer = $this->GetBuffer('ReceiveBuffer') . $raw;

        while (strlen($buffer) >= 5) {
            $bytes = array_values(unpack('C*', $buffer));

            // Resync to next header byte if corrupted
            if ($bytes[0] !== self::HEADER_RECV) {
                $pos = strpos($buffer, chr(self::HEADER_RECV), 1);
                $buffer = ($pos !== false) ? substr($buffer, $pos) : '';
                continue;
            }

            // Need at least 5 bytes to read the Length field (byte[4])
            if (count($bytes) < 5) {
                break;
            }

            $length    = $bytes[4];
            $totalLen  = $length + 4; // header(4) + LEN(1) + DC(1) + DATA(LEN-3) + CHK(1) = LEN+4

            if (count($bytes) < $totalLen) {
                break; // Wait for more data
            }

            $packet = array_slice($bytes, 0, $totalLen);
            $buffer = substr($buffer, $totalLen);

            // Filter by Monitor ID (ignore packets for other monitors on the bus)
            $monitorId = $this->ReadPropertyInteger('MonitorID');
            if ($packet[1] !== $monitorId) {
                continue;
            }

            // Validate XOR checksum
            $xor = 0;
            for ($i = 0; $i < $totalLen - 1; $i++) {
                $xor ^= $packet[$i];
            }
            if ($xor !== $packet[$totalLen - 1]) {
                $this->LogMessage(
                    sprintf('Checksum mismatch: expected 0x%02X, got 0x%02X', $xor, $packet[$totalLen - 1]),
                    KL_ERROR
                );
                continue;
            }

            // Extract DATA bytes: start at byte[6], length = LEN-3
            $dataCount    = $length - 3;
            $responseData = array_slice($packet, 6, $dataCount);

            if ($dataCount >= 1) {
                $this->ParseReport($responseData);
            }
        }

        $this->SetBuffer('ReceiveBuffer', $buffer);
    }

    // ── User action handler (WebFront / scripts) ───────────────────────────────

    public function RequestAction(string $ident, $value): void
    {
        switch ($ident) {
            // ── Power / System ────────────────────────────────────────────────
            case 'ColdStartBehavior':
                $this->SendPacket([self::CMD_COLD_START_SET, (int) $value]);
                $this->SendPacket([self::CMD_COLD_START_GET]);
                break;

            case 'IRRemoteLock':
                $this->SendPacket([self::CMD_IR_REMOTE_LOCK_SET, (int) $value]);
                $this->SendPacket([self::CMD_IR_REMOTE_LOCK_GET]);
                break;

            case 'KeypadLock':
                $this->SendPacket([self::CMD_KEYPAD_LOCK_SET, (int) $value]);
                $this->SendPacket([self::CMD_KEYPAD_LOCK_GET]);
                break;

            // ── Video Parameters (sent as a single compound packet) ───────────
            case 'Brightness':
            case 'ColorSaturation':
            case 'Contrast':
            case 'Sharpness':
            case 'Tint':
            case 'BlackLevel':
            case 'Gamma':
                $params = $this->CollectVideoParams();
                $params[$ident] = (int) $value;
                $this->SendVideoParams($params);
                $this->SendPacket([self::CMD_VIDEO_PARAMS_GET]);
                break;

            // ── Color Temperature ─────────────────────────────────────────────
            case 'ColorTemperature':
                $this->SendPacket([self::CMD_COLOR_TEMP_SET, (int) $value]);
                $this->SendPacket([self::CMD_COLOR_TEMP_GET]);
                break;

            // ── Color Parameters (sent as a single compound packet) ───────────
            case 'RedGain':
            case 'GreenGain':
            case 'BlueGain':
            case 'RedOffset':
            case 'GreenOffset':
            case 'BlueOffset':
                $params = $this->CollectColorParams();
                $params[$ident] = (int) $value;
                $this->SendColorParams($params);
                $this->SendPacket([self::CMD_COLOR_PARAMS_GET]);
                break;

            // ── Picture Format ────────────────────────────────────────────────
            case 'PictureFormat':
                $this->SendPacket([self::CMD_PICTURE_FORMAT_SET, (int) $value]);
                $this->SendPacket([self::CMD_PICTURE_FORMAT_GET]);
                break;

            // ── Pixel Shift ───────────────────────────────────────────────────
            case 'PixelShift':
                // IPS value 0-91 maps directly to protocol byte 0x00-0x5B
                $this->SendPacket([self::CMD_PIXEL_SHIFT_SET, (int) $value]);
                $this->SendPacket([self::CMD_PIXEL_SHIFT_GET]);
                break;

            default:
                $this->LogMessage("RequestAction: unknown ident '$ident'", KL_WARNING);
        }
    }

    // ── Public API functions ───────────────────────────────────────────────────

    /**
     * Triggers a full status poll (all operational parameters).
     */
    public function PollStatus(): void
    {
        $this->SendPacket([self::CMD_POWER_GET]);
        $this->SendPacket([self::CMD_CURRENT_SOURCE_GET]);
        $this->SendPacket([self::CMD_VIDEO_PARAMS_GET]);
        $this->SendPacket([self::CMD_COLOR_TEMP_GET]);
        $this->SendPacket([self::CMD_COLOR_PARAMS_GET]);
        $this->SendPacket([self::CMD_PICTURE_FORMAT_GET]);
        $this->SendPacket([self::CMD_VOLUME_GET]);
        $this->SendPacket([self::CMD_OPERATING_HOURS_GET, 0x02]);
        $this->SendPacket([self::CMD_PIXEL_SHIFT_GET]);
        $this->SendPacket([self::CMD_IR_REMOTE_LOCK_GET]);
        $this->SendPacket([self::CMD_KEYPAD_LOCK_GET]);
        $this->SendPacket([self::CMD_COLD_START_GET]);
    }

    /**
     * Fetches static device information (model, firmware, serial number, platform).
     * Call once after pairing; not polled on every cycle.
     */
    public function FetchDeviceInfo(): void
    {
        $this->SendPacket([self::CMD_MODEL_INFO_GET, 0x00]); // Model number
        $this->SendPacket([self::CMD_MODEL_INFO_GET, 0x01]); // FW version
        $this->SendPacket([self::CMD_MODEL_INFO_GET, 0x02]); // Build date
        $this->SendPacket([self::CMD_PLATFORM_LABEL_GET, 0x00]); // OTSC version
        $this->SendPacket([self::CMD_PLATFORM_LABEL_GET, 0x01]); // Platform label
        $this->SendPacket([self::CMD_SERIAL_CODE_GET]);
    }

    /**
     * Sends the Power Off command.
     * Power-On via LAN is not supported — use Wake-on-LAN separately.
     */
    public function PowerOff(): void
    {
        $this->SendPacket([self::CMD_POWER_SET, 0x01]);
    }

    /**
     * Switches the active input source.
     *
     * @param int $sourceCode Protocol source code (e.g. 0x0D = HDMI 1).
     */
    public function SetInputSource(int $sourceCode): void
    {
        $this->SendPacket([self::CMD_INPUT_SOURCE_SET, $sourceCode, 0x00, 0x00, 0x00]);
        $this->SendPacket([self::CMD_CURRENT_SOURCE_GET]);
    }

    /**
     * Sets the audio volume.
     *
     * @param int $volume    Speaker volume (0–100).
     * @param int $audioOut  Audio-out volume (0–100).
     */
    public function SetVolume(int $volume, int $audioOut): void
    {
        $this->SendPacket([self::CMD_VOLUME_SET, $volume, $audioOut]);
    }

    /**
     * Triggers VGA auto-adjust (VGA source only).
     */
    public function TriggerVGAAutoAdjust(): void
    {
        $this->SendPacket([self::CMD_VGA_AUTO_ADJUST, 0x40, 0x00]);
    }

    // ── Packet builder & sender ────────────────────────────────────────────────

    /**
     * Builds a binary SICP command packet.
     *
     * Packet layout:
     *   [A6] [MonID] [00] [00] [00] [LEN] [01] [DATA…] [CHK]
     * where LEN = count(DATA) + 3  (LEN itself + DataControl + DATA + CHK)
     * and CHK = XOR of all preceding bytes.
     *
     * @param int   $monitorId Display address (1–255; 0 = broadcast).
     * @param array $data      DATA bytes (command code + payload).
     * @return string Binary packet string.
     */
    private function BuildPacket(int $monitorId, array $data): string
    {
        $n      = count($data);
        $length = $n + 3; // LEN(1) + DataControl(1) + DATA(n) + CHK(1)

        $bytes = array_merge(
            [self::HEADER_SEND, $monitorId, self::CATEGORY, self::CODE0, self::CODE1, $length, self::DATA_CTRL],
            $data
        );

        $xor = 0;
        foreach ($bytes as $b) {
            $xor ^= $b;
        }
        $bytes[] = $xor;

        return pack('C*', ...$bytes);
    }

    /**
     * Sends a DATA array to the display via the parent Client Socket.
     *
     * @param array $data DATA bytes (command code + payload).
     * @return bool True if sent successfully.
     */
    private function SendPacket(array $data): bool
    {
        if (!$this->HasActiveParent()) {
            $this->LogMessage('Cannot send packet: parent socket not connected', KL_WARNING);
            return false;
        }

        $monitorId = $this->ReadPropertyInteger('MonitorID');
        $packet    = $this->BuildPacket($monitorId, $data);

        $json = json_encode([
            'DataID' => self::SEND_DATA_ID,
            'Buffer' => utf8_encode($packet),
        ]);

        $this->SendDataToParent($json);
        return true;
    }

    // ── Response parser ────────────────────────────────────────────────────────

    /**
     * Dispatches a validated response DATA array to the appropriate parse handler.
     *
     * @param array $data DATA bytes from the response packet (DATA[0] = command echo).
     */
    private function ParseReport(array $data): void
    {
        if (empty($data)) {
            return;
        }

        switch ($data[0]) {
            case 0x00:
                $this->ParseACK($data);
                break;
            case self::CMD_POWER_GET:
                $this->ParsePowerState($data);
                break;
            case self::CMD_KEYPAD_LOCK_GET:
                $this->ParseKeypadLock($data);
                break;
            case self::CMD_IR_REMOTE_LOCK_GET:
                $this->ParseIRRemoteLock($data);
                break;
            case self::CMD_MODEL_INFO_GET:
                $this->ParseModelInfo($data);
                break;
            case self::CMD_PLATFORM_LABEL_GET:
                $this->ParsePlatformLabel($data);
                break;
            case self::CMD_COLD_START_GET:
                $this->ParseColdStart($data);
                break;
            case self::CMD_CURRENT_SOURCE_GET:
                $this->ParseCurrentSource($data);
                break;
            case self::CMD_VIDEO_PARAMS_GET:
                $this->ParseVideoParams($data);
                break;
            case self::CMD_COLOR_TEMP_GET:
                $this->ParseColorTemperature($data);
                break;
            case self::CMD_COLOR_PARAMS_GET:
                $this->ParseColorParams($data);
                break;
            case self::CMD_PICTURE_FORMAT_GET:
                $this->ParsePictureFormat($data);
                break;
            case self::CMD_VOLUME_GET:
                $this->ParseVolume($data);
                break;
            case self::CMD_OPERATING_HOURS_GET:
                $this->ParseMiscInfo($data);
                break;
            case self::CMD_SERIAL_CODE_GET:
                $this->ParseSerialCode($data);
                break;
            case self::CMD_SCHEDULING_GET:
                $this->ParseScheduling($data);
                break;
            case self::CMD_PIXEL_SHIFT_GET:
                $this->ParsePixelShift($data);
                break;
            default:
                $this->LogMessage(
                    sprintf('ParseReport: unknown command code 0x%02X', $data[0]),
                    KL_WARNING
                );
        }
    }

    // ── Individual parse handlers ──────────────────────────────────────────────

    private function ParseACK(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $status = $data[1];
        $labels = [
            self::ACK_COMPLETED       => 'Completed (ACK)',
            self::ACK_LIMIT_OVER_HIGH => 'Limit Over – value above upper limit',
            self::ACK_LIMIT_OVER_LOW  => 'Limit Over – value below lower limit',
            self::ACK_CANCELLED       => 'Command cancelled (invalid data or not permitted)',
            self::ACK_PARSE_ERROR     => 'Parse Error (checksum or format error)',
        ];
        $label = $labels[$status] ?? sprintf('Unknown status 0x%02X', $status);

        if ($status === self::ACK_COMPLETED) {
            $this->LogMessage("ACK: $label", KL_DEBUG);
        } else {
            $this->LogMessage("NACK: $label", KL_WARNING);
        }
    }

    private function ParsePowerState(array $data): void
    {
        // DATA = [0x19, state] where state 0x01=On, 0x02=Off (standby)
        if (count($data) < 2) {
            return;
        }
        $this->SetValue('PowerState', $data[1] === 0x01);
    }

    private function ParseKeypadLock(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $this->SetValue('KeypadLock', $data[1]);
    }

    private function ParseIRRemoteLock(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $this->SetValue('IRRemoteLock', $data[1]);
    }

    private function ParseModelInfo(array $data): void
    {
        // DATA = [0xA1, subtype, ...ASCII bytes...]
        if (count($data) < 3) {
            return;
        }
        $text = '';
        for ($i = 2; $i < count($data); $i++) {
            if ($data[$i] === 0x00) {
                break;
            }
            $text .= chr($data[$i]);
        }
        switch ($data[1]) {
            case 0x00:
                $this->SetValue('ModelNumber', trim($text));
                break;
            case 0x01:
                $this->SetValue('FirmwareVersion', trim($text));
                break;
            case 0x02:
                $this->SetValue('BuildDate', trim($text));
                break;
        }
    }

    private function ParsePlatformLabel(array $data): void
    {
        // DATA = [0xA2, subtype, ...ASCII bytes...]
        if (count($data) < 3) {
            return;
        }
        $text = '';
        for ($i = 2; $i < count($data); $i++) {
            if ($data[$i] === 0x00) {
                break;
            }
            $text .= chr($data[$i]);
        }
        $this->SetValue('PlatformLabel', trim($text));
    }

    private function ParseColdStart(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $this->SetValue('ColdStartBehavior', $data[1]);
    }

    private function ParseCurrentSource(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $code  = $data[1];
        $label = self::SOURCE_MAP[$code] ?? sprintf('Unknown (0x%02X)', $code);
        $this->SetValue('CurrentSource', $label);
    }

    private function ParseVideoParams(array $data): void
    {
        // DATA = [0x33, brightness, colorSat, contrast, sharpness, tint, blackLevel, gamma]
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
        if (count($data) < 2) {
            return;
        }
        $this->SetValue('ColorTemperature', $data[1]);
    }

    private function ParseColorParams(array $data): void
    {
        // DATA = [0x37, redGain, greenGain, blueGain, redOffset, greenOffset, blueOffset]
        if (count($data) < 7) {
            return;
        }
        $this->SetValue('RedGain',    $data[1]);
        $this->SetValue('GreenGain',  $data[2]);
        $this->SetValue('BlueGain',   $data[3]);
        $this->SetValue('RedOffset',  $data[4]);
        $this->SetValue('GreenOffset', $data[5]);
        $this->SetValue('BlueOffset', $data[6]);
    }

    private function ParsePictureFormat(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $this->SetValue('PictureFormat', $data[1]);
    }

    private function ParseVolume(array $data): void
    {
        // Volume is not stored as an IPS variable in this version; log only.
        if (count($data) < 3) {
            return;
        }
        $this->LogMessage(
            sprintf('Volume – Speaker: %d, Audio Out: %d', $data[1], $data[2]),
            KL_DEBUG
        );
    }

    private function ParseMiscInfo(array $data): void
    {
        // Operating Hours: DATA = [0x0F, subtype, hoursHigh, hoursLow, ...]
        if (count($data) < 4) {
            return;
        }
        if ($data[1] === 0x02) {
            $hours = ($data[2] << 8) | $data[3];
            $this->SetValue('OperatingHours', $hours);
        }
    }

    private function ParseSerialCode(array $data): void
    {
        if (count($data) < 2) {
            return;
        }
        $text = '';
        for ($i = 1; $i < count($data); $i++) {
            if ($data[$i] === 0x00) {
                break;
            }
            $text .= chr($data[$i]);
        }
        $this->SetValue('SerialCode', trim($text));
    }

    private function ParseScheduling(array $data): void
    {
        // Scheduling data is logged only; not mapped to IPS variables in this version.
        $this->LogMessage(
            'Scheduling data received: ' . implode(' ', array_map(fn ($b) => sprintf('%02X', $b), $data)),
            KL_DEBUG
        );
    }

    private function ParsePixelShift(array $data): void
    {
        // DATA = [0xB1, interval]
        // interval: 0x00=Off, 0x01-0x5A=10s-900s, 0x5B=Auto
        // IPS variable 0-91 maps 1:1 to protocol bytes 0x00-0x5B
        if (count($data) < 2) {
            return;
        }
        $this->SetValue('PixelShift', $data[1]);
    }

    // ── Compound-packet helpers ────────────────────────────────────────────────

    /**
     * Reads current video parameter values from IPS variables.
     *
     * @return array Associative array of current parameter values.
     */
    private function CollectVideoParams(): array
    {
        return [
            'Brightness'      => $this->GetValue('Brightness'),
            'ColorSaturation' => $this->GetValue('ColorSaturation'),
            'Contrast'        => $this->GetValue('Contrast'),
            'Sharpness'       => $this->GetValue('Sharpness'),
            'Tint'            => $this->GetValue('Tint'),
            'BlackLevel'      => $this->GetValue('BlackLevel'),
            'Gamma'           => $this->GetValue('Gamma'),
        ];
    }

    /**
     * Sends a Video Parameters Set packet with the given values.
     *
     * All 7 parameters must be supplied in one packet; use CollectVideoParams()
     * to read current values for unchanged parameters.
     *
     * @param array $p Associative array with keys matching CollectVideoParams().
     */
    private function SendVideoParams(array $p): void
    {
        $this->SendPacket([
            self::CMD_VIDEO_PARAMS_SET,
            (int) $p['Brightness'],
            (int) $p['ColorSaturation'],
            (int) $p['Contrast'],
            (int) $p['Sharpness'],
            (int) $p['Tint'],
            (int) $p['BlackLevel'],
            (int) $p['Gamma'],
        ]);
    }

    /**
     * Reads current color parameter values from IPS variables.
     *
     * @return array Associative array of current gain/offset values.
     */
    private function CollectColorParams(): array
    {
        return [
            'RedGain'    => $this->GetValue('RedGain'),
            'GreenGain'  => $this->GetValue('GreenGain'),
            'BlueGain'   => $this->GetValue('BlueGain'),
            'RedOffset'  => $this->GetValue('RedOffset'),
            'GreenOffset' => $this->GetValue('GreenOffset'),
            'BlueOffset' => $this->GetValue('BlueOffset'),
        ];
    }

    /**
     * Sends a Color Parameters Set packet with the given values.
     *
     * All 6 channels must be supplied in one packet.
     *
     * @param array $p Associative array with keys matching CollectColorParams().
     */
    private function SendColorParams(array $p): void
    {
        $this->SendPacket([
            self::CMD_COLOR_PARAMS_SET,
            (int) $p['RedGain'],
            (int) $p['GreenGain'],
            (int) $p['BlueGain'],
            (int) $p['RedOffset'],
            (int) $p['GreenOffset'],
            (int) $p['BlueOffset'],
        ]);
    }

    // ── Variable and profile registration ─────────────────────────────────────

    private function RegisterVariables(): void
    {
        // ── Read-only status ──────────────────────────────────────────────────

        $this->RegisterVariableBoolean('PowerState', $this->Translate('Power State'), '~Switch');
        // No EnableAction — power-on via LAN is not supported

        $this->RegisterVariableString('CurrentSource', $this->Translate('Current Source'));

        $this->RegisterVariableInteger('OperatingHours', $this->Translate('Operating Hours'), 'IIY.Hours');

        $this->RegisterVariableString('ModelNumber',     $this->Translate('Model Number'));
        $this->RegisterVariableString('FirmwareVersion', $this->Translate('Firmware Version'));
        $this->RegisterVariableString('BuildDate',       $this->Translate('Build Date'));
        $this->RegisterVariableString('PlatformLabel',   $this->Translate('Platform Label'));
        $this->RegisterVariableString('SerialCode',      $this->Translate('Serial Code'));

        // ── Power / System ────────────────────────────────────────────────────

        $this->RegisterVariableInteger('ColdStartBehavior', $this->Translate('Cold Start Behavior'), 'IIY.ColdStartBehavior');
        $this->EnableAction('ColdStartBehavior');

        $this->RegisterVariableInteger('IRRemoteLock', $this->Translate('IR Remote Lock'), 'IIY.LockMode');
        $this->EnableAction('IRRemoteLock');

        $this->RegisterVariableInteger('KeypadLock', $this->Translate('Keypad Lock'), 'IIY.LockMode');
        $this->EnableAction('KeypadLock');

        // ── Video ─────────────────────────────────────────────────────────────

        foreach (['Brightness', 'ColorSaturation', 'Contrast', 'Sharpness', 'Tint', 'BlackLevel'] as $ident) {
            $label = $this->Translate(preg_replace('/([A-Z])/', ' $1', $ident));
            $this->RegisterVariableInteger($ident, $label, 'IIY.Percent');
            $this->EnableAction($ident);
        }

        $this->RegisterVariableInteger('Gamma', $this->Translate('Gamma'), 'IIY.Gamma');
        $this->EnableAction('Gamma');

        $this->RegisterVariableInteger('ColorTemperature', $this->Translate('Color Temperature'), 'IIY.ColorTemperature');
        $this->EnableAction('ColorTemperature');

        foreach (['RedGain', 'GreenGain', 'BlueGain', 'RedOffset', 'GreenOffset', 'BlueOffset'] as $ident) {
            $label = $this->Translate(preg_replace('/([A-Z])/', ' $1', $ident));
            $this->RegisterVariableInteger($ident, $label, 'IIY.RGBValue');
            $this->EnableAction($ident);
        }

        $this->RegisterVariableInteger('PictureFormat', $this->Translate('Picture Format'), 'IIY.PictureFormat');
        $this->EnableAction('PictureFormat');

        $this->RegisterVariableInteger('PixelShift', $this->Translate('Pixel Shift'), 'IIY.PixelShift');
        $this->EnableAction('PixelShift');
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('IIY.ColdStartBehavior')) {
            $this->RegisterProfileIntegerEx(
                'IIY.ColdStartBehavior',
                'Power',
                '',
                '',
                [
                    [0, $this->Translate('Power Off'),   '', -1],
                    [1, $this->Translate('Forced On'),   '', -1],
                    [2, $this->Translate('Last Status'), '', -1],
                ]
            );
        }

        if (!IPS_VariableProfileExists('IIY.LockMode')) {
            $this->RegisterProfileIntegerEx(
                'IIY.LockMode',
                'Lock',
                '',
                '',
                [
                    [1, $this->Translate('Unlock All'),                     '', -1],
                    [2, $this->Translate('Lock All'),                       '', -1],
                    [3, $this->Translate('Lock All but Power'),             '', -1],
                    [4, $this->Translate('Lock All but Volume'),            '', -1],
                    [7, $this->Translate('Lock All but Power and Volume'),  '', -1],
                ]
            );
        }

        if (!IPS_VariableProfileExists('IIY.Gamma')) {
            $this->RegisterProfileIntegerEx(
                'IIY.Gamma',
                'Graph',
                '',
                '',
                [
                    [1, 'Native',  '', -1],
                    [2, 'S-Gamma', '', -1],
                    [3, '2.2',     '', -1],
                    [4, '2.4',     '', -1],
                    [5, 'DICOM',   '', -1],
                ]
            );
        }

        if (!IPS_VariableProfileExists('IIY.ColorTemperature')) {
            $this->RegisterProfileIntegerEx(
                'IIY.ColorTemperature',
                'Bulb',
                '',
                '',
                [
                    [0,  'User 1',  '', -1],
                    [1,  'Native',  '', -1],
                    [3,  '10000 K', '', -1],
                    [4,  '9300 K',  '', -1],
                    [5,  '7500 K',  '', -1],
                    [6,  '6500 K',  '', -1],
                    [9,  '5000 K',  '', -1],
                    [10, '4000 K',  '', -1],
                    [13, '3000 K',  '', -1],
                    [18, 'User 2',  '', -1],
                ]
            );
        }

        if (!IPS_VariableProfileExists('IIY.PictureFormat')) {
            $this->RegisterProfileIntegerEx(
                'IIY.PictureFormat',
                'Display',
                '',
                '',
                [
                    [0, 'Normal 4:3', '', -1],
                    [1, 'Custom',     '', -1],
                    [2, 'Real 1:1',   '', -1],
                    [3, 'Full',       '', -1],
                    [4, '21:9',       '', -1],
                    [5, 'Dynamic',    '', -1],
                    [6, '16:9',       '', -1],
                ]
            );
        }

        if (!IPS_VariableProfileExists('IIY.Percent')) {
            $this->RegisterProfileInteger('IIY.Percent', 'Intensity', '', ' %', 0, 100, 1);
        }

        if (!IPS_VariableProfileExists('IIY.RGBValue')) {
            $this->RegisterProfileInteger('IIY.RGBValue', 'Paintbrush', '', '', 0, 255, 1);
        }

        if (!IPS_VariableProfileExists('IIY.Hours')) {
            $this->RegisterProfileInteger('IIY.Hours', 'Clock', '', ' h', 0, 999999, 1);
        }

        if (!IPS_VariableProfileExists('IIY.PixelShift')) {
            IPS_CreateVariableProfile('IIY.PixelShift', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('IIY.PixelShift', 'Move');
            IPS_SetVariableProfileAssociation('IIY.PixelShift', 0, $this->Translate('Off'), '', -1);
            for ($i = 1; $i <= 90; $i++) {
                IPS_SetVariableProfileAssociation('IIY.PixelShift', $i, ($i * 10) . ' s', '', -1);
            }
            IPS_SetVariableProfileAssociation('IIY.PixelShift', 91, $this->Translate('Auto'), '', -1);
        }
    }
}
