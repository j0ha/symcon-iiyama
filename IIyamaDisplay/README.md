# IIyamaDisplay

IP-Symcon Device Module for **iiyama ProLite LH60-series digital signage displays** over LAN using the proprietary SICP binary protocol on TCP port 5000.

## Architecture

```
Client Socket (IO)        ← one shared socket per physical display IP/port (port 5000)
    └── IIyamaDisplay     ← one instance per Monitor ID
```

Multiple `IIyamaDisplay` instances may share the same Client Socket when displays are daisy-chained on different Monitor IDs.

## Setup

1. Add a **Client Socket** instance pointing at the display's IP address and TCP port `5000`.
2. Create an **IIyamaDisplay** instance with the Client Socket as its parent.
3. Set the **Monitor ID** (1–255; `0` is broadcast and triggers no ACK).
4. Optionally set a **Polling Interval** (seconds, `0` disables polling).

## Power-On is NOT supported via LAN

The SICP protocol does not support powering the display on. To turn the display on, use **Wake-on-LAN** (a separate IP-Symcon module or external mechanism). Only Power-Off is exposed by this module.

## Public API (callable via IPS scripts)

| Function | Description |
| --- | --- |
| `IIY_PollStatus(int $InstanceID)` | Trigger a full status poll |
| `IIY_FetchDeviceInfo(int $InstanceID)` | Fetch model, firmware, serial, platform info |
| `IIY_PowerOff(int $InstanceID)` | Send Power-Off command |
| `IIY_SetInputSource(int $InstanceID, int $sourceCode)` | Switch input source by raw code |
| `IIY_SetVolume(int $InstanceID, int $volume, int $audioOut)` | Set speaker / line-out volume (0–100) |
| `IIY_TriggerVGAAutoAdjust(int $InstanceID)` | Trigger VGA Auto Adjust (only on a VGA source) |

In addition, all writable variables react to WebFront / IPS actions via `RequestAction`.
