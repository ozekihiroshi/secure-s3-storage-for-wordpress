#!/usr/bin/env python3
"""Build deterministic WordPress.org icon and banner exports."""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps


ROOT = Path(__file__).resolve().parents[1]
ASSET_DIR = ROOT / "wordpress-org-assets"
SOURCE_DIR = ASSET_DIR / "source"


def find_font(bold: bool) -> Path:
    candidates = (
        [Path("C:/Windows/Fonts/segoeuib.ttf")]
        if bold
        else [Path("C:/Windows/Fonts/segoeui.ttf")]
    )
    candidates.extend(
        [
            Path(
                "/usr/share/fonts/truetype/dejavu/"
                + ("DejaVuSans-Bold.ttf" if bold else "DejaVuSans.ttf")
            )
        ]
    )

    for candidate in candidates:
        if candidate.is_file():
            return candidate

    raise FileNotFoundError("Segoe UI or DejaVu Sans font was not found.")


def build_icon(source: Image.Image, size: int) -> Image.Image:
    icon = ImageOps.fit(
        source.convert("RGBA"),
        (size, size),
        method=Image.Resampling.LANCZOS,
    )
    return icon.filter(ImageFilter.UnsharpMask(radius=0.7, percent=115, threshold=2))


def build_retina_banner(source: Image.Image) -> Image.Image:
    banner = ImageOps.fit(
        source.convert("RGB"),
        (1544, 500),
        method=Image.Resampling.LANCZOS,
        centering=(0.5, 0.5),
    ).convert("RGBA")

    overlay = Image.new("RGBA", banner.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)

    # Increase contrast behind the copy without hiding the generated backdrop.
    draw.rounded_rectangle(
        (54, 68, 910, 432),
        radius=28,
        fill=(2, 19, 48, 126),
        outline=(26, 214, 190, 50),
        width=2,
    )
    draw.rounded_rectangle((84, 103, 198, 111), radius=4, fill=(24, 226, 184, 255))

    title_font = ImageFont.truetype(str(find_font(bold=True)), 72)
    subtitle_font = ImageFont.truetype(str(find_font(bold=False)), 29)

    shadow = (0, 0, 0, 155)
    white = (248, 252, 255, 255)
    muted = (198, 225, 239, 255)

    title_lines = ("Ozeki Database", "Backup for S3")
    title_y = 126
    for line in title_lines:
        draw.text((87, title_y + 3), line, font=title_font, fill=shadow)
        draw.text((84, title_y), line, font=title_font, fill=white)
        title_y += 80

    subtitle = "Security-focused WordPress database backups to S3"
    draw.text((86, 323), subtitle, font=subtitle_font, fill=muted)

    return Image.alpha_composite(banner, overlay).convert("RGB")


def build_screenshot(
    source_name: str,
    expected_size: tuple[int, int],
    box: tuple[int, int, int, int],
) -> Image.Image:
    source_path = SOURCE_DIR / source_name
    with Image.open(source_path) as source:
        if source.size != expected_size:
            raise RuntimeError(
                f"{source_name}: expected source size {expected_size}, got {source.size}"
            )
        return source.convert("RGB").crop(box)


def main() -> None:
    ASSET_DIR.mkdir(parents=True, exist_ok=True)

    with Image.open(SOURCE_DIR / "icon-source.png") as source_icon:
        build_icon(source_icon, 256).save(
            ASSET_DIR / "icon-256x256.png",
            optimize=True,
        )
        build_icon(source_icon, 128).save(
            ASSET_DIR / "icon-128x128.png",
            optimize=True,
        )

    with Image.open(SOURCE_DIR / "banner-source.png") as source_banner:
        retina = build_retina_banner(source_banner)
        retina.save(ASSET_DIR / "banner-1544x500.png", optimize=True)
        retina.resize((772, 250), Image.Resampling.LANCZOS).save(
            ASSET_DIR / "banner-772x250.png",
            optimize=True,
        )

    # Remove browser chrome, account details, update notices, and the localized
    # WordPress navigation. Keep only the plugin-owned administration content.
    build_screenshot(
        "screenshot-1-source.png",
        (1834, 891),
        (156, 104, 1834, 875),
    ).save(ASSET_DIR / "screenshot-1.png", optimize=True)
    build_screenshot(
        "screenshot-2-source.png",
        (1852, 566),
        (159, 75, 1852, 515),
    ).save(ASSET_DIR / "screenshot-2.png", optimize=True)

    expected = {
        "icon-128x128.png": (128, 128),
        "icon-256x256.png": (256, 256),
        "banner-772x250.png": (772, 250),
        "banner-1544x500.png": (1544, 500),
        "screenshot-1.png": (1678, 771),
        "screenshot-2.png": (1693, 440),
    }
    for filename, dimensions in expected.items():
        with Image.open(ASSET_DIR / filename) as image:
            if image.size != dimensions:
                raise RuntimeError(f"{filename}: expected {dimensions}, got {image.size}")
        print(f"built {filename} {dimensions[0]}x{dimensions[1]}")


if __name__ == "__main__":
    main()
