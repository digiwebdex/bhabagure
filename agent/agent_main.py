"""Entry point for PyInstaller (it needs a script, not a package's __main__)."""

import sys

from bhabaghure_attendance.__main__ import main

if __name__ == "__main__":
    sys.exit(main())
