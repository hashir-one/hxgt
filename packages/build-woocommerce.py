"""Build the installable plugin with the shared verification library, no secrets."""
from pathlib import Path
import argparse
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument("output", type=Path, help="Output ZIP path")
args = parser.parse_args()
root = Path(__file__).resolve().parent
args.output.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(args.output, "w", zipfile.ZIP_DEFLATED) as archive:
    for source in sorted((root / "hx-upi-woocommerce").glob("*.php")):
        archive.write(source, "hx-upi-woocommerce/" + source.name)
    for name in ("VerificationResult", "TwoOfTwoVerifier", "RequestSigner"):
        archive.write(root / "hx-upi-merchant-php/src" / (name + ".php"), "hx-upi-woocommerce/lib/" + name + ".php")
print(args.output)
