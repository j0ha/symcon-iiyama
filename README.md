# IIyama Display — IP-Symcon Module

Controls iiyama ProLite LH60-series digital signage displays over LAN using the
proprietary SICP binary protocol (TCP port 5000).

---

## Requirements

- IP-Symcon 6.x or later
- Display connected to the same network, TCP port 5000 reachable

## Installation

Add this repository URL in the IP-Symcon Module Store or copy the `IIyamaDisplay/`
directory into your IP-Symcon `modules/` folder and run **Update Module List** in the
console.

---

## Setup

### 1. Create a Client Socket instance

In the IP-Symcon management console:

1. Create a new instance → **Client Socket** (found under *I/O Instances*).
2. Set **Host** to the display's IP address.
3. Set **Port** to **5000**.
4. Set **Open** to *Automatically*.
5. Save.

### 2. Create an IIyama Display instance

1. Create a new instance → **IIyama Display**.
2. When asked for a parent, select the Client Socket created above.
3. Open the instance configuration:
   - **Monitor ID** — set to the display's hardware address (1–255; default 1).
     If you daisy-chain multiple displays on one TCP connection, create one
     IIyama Display instance per display with the corresponding Monitor ID.
   - **Polling Interval** — seconds between automatic status polls (0 = disabled).
4. Click **Fetch Device Info Now** to populate model/firmware/serial variables.
5. Save.

### Multiple displays on one TCP connection

Create **one Client Socket** pointing to the first display's IP, then create
**one IIyama Display instance per Monitor ID** all parented to that same socket.
The module filters incoming responses by Monitor ID automatically.

---

## Power-On limitation

> **Power-On via LAN is not supported** by the SICP protocol.

The display must be powered on via:
- The physical power button, or
- **Wake-on-LAN** — use the IP-Symcon *WakeOnLan* module or a custom script that
  sends a WoL magic packet to the display's MAC address.

Power-Off works normally via the `IIY_PowerOff()` function or the *Cold Start
Behavior* variable.

---

## Variables

### Read-only status

| Variable | Type | Description |
|---|---|---|
| Power State | Boolean | Current on/off state |
| Current Source | String | Active input (e.g. "HDMI 1") |
| Operating Hours | Integer | Total backlight hours |
| Model Number | String | Model identifier |
| Firmware Version | String | Firmware build string |
| Build Date | String | Firmware build date |
| Platform Label | String | OS/platform label |
| Serial Code | String | Unit serial number |

### Read/Write controls

| Variable | Type | Description |
|---|---|---|
| Cold Start Behavior | Integer | Power state after mains power loss |
| IR Remote Lock | Integer | IR remote control lock mode |
| Keypad Lock | Integer | Front panel key lock mode |
| Brightness | Integer | 0–100 % |
| Color Saturation | Integer | 0–100 % |
| Contrast | Integer | 0–100 % |
| Sharpness | Integer | 0–100 % |
| Tint | Integer | 0–100 % |
| Black Level | Integer | 0–100 % |
| Gamma | Integer | Native / S-Gamma / 2.2 / 2.4 / DICOM |
| Color Temperature | Integer | Preset or User calibration |
| Red/Green/Blue Gain | Integer | 0–255 |
| Red/Green/Blue Offset | Integer | 0–255 |
| Picture Format | Integer | Normal 4:3 / 16:9 / Full / etc. |
| Pixel Shift | Integer | Off / 10 s – 900 s / Auto |

---

## Script API

All functions are callable from IPS scripts using the module prefix `IIY_`:

```php
IIY_PollStatus(int $InstanceID);
IIY_FetchDeviceInfo(int $InstanceID);
IIY_PowerOff(int $InstanceID);
IIY_SetInputSource(int $InstanceID, int $sourceCode);
IIY_SetVolume(int $InstanceID, int $volume, int $audioOut);
IIY_TriggerVGAAutoAdjust(int $InstanceID);
```

### Source codes for `IIY_SetInputSource`

| Code | Source |
|---|---|
| 0x05 | VGA |
| 0x06 | HDMI 2 |
| 0x0A | DisplayPort 1 |
| 0x0B | Card OPS |
| 0x0D | HDMI 1 |
| 0x0E | DVI-D |
| 0x0F | HDMI 3 |
| 0x10 | Browser |
| 0x13 | Internal Storage |
| 0x16 | Media Player |
| 0x17 | PDF Player |
| 0x18 | Custom |
| 0x19 | HDMI 4 |

---

## OSD / NAV caveat

Some menu-navigation (NAV) commands listed in the iiyama RS232/LAN command document
are not implemented in this module. OSD responses may arrive with different response
codes depending on display firmware version. If you need OSD control, refer to the
iiyama command document and extend `ParseReport` and `RequestAction` accordingly.

---

## License

MIT
