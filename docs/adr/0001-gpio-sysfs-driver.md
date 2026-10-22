# ADR 0001: GPIO Driver Implementation via Linux sysfs

## Status
Accepted

## Context
`PRD.md:329` specifies `libgpiod` as the target GPIO access interface for feedback peripherals (Green LED, Red LED, Buzzer). However, the engine implementation in `clients/edge-engine-rs/src/hardware/gpio.rs` uses the Linux `/sys/class/gpio` sysfs interface.

## Decision
We retain the `/sys/class/gpio` direct sysfs implementation for the following reasons:
1. **Zero External C Dynamic Library Dependencies**: Using sysfs allows the native Rust engine to compile without requiring `libgpiod-dev` headers or C ABI bindings (`libgpiod-sys`), ensuring seamless static cross-compilation for ARMv7/aarch64 SBCs.
2. **Allwinner H6 / Armbian Compatibility**: The deployment target (Orange Pi Lite 2 running Armbian / Linux 5.x/6.x legacy kernel) maintains active `/sys/class/gpio` compatibility.
3. **Container & Minimal Rootfs Portability**: Direct file I/O to sysfs requires only exporting pins and writing to `direction` and `value`, working identically across minimal systemd setups without extra shared object (`.so`) dependencies.

## Consequences
- While sysfs GPIO is deprecated in upstream mainline Linux in favor of character device ABI (`/dev/gpiochipX`), it remains fully functional and reliable on the current target SBC OS image.
- Migration to native `gpiocdev` / `libgpiod` can be scheduled if the production OS drops sysfs support in future kernel upgrades.
