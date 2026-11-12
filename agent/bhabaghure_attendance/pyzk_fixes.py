"""A fix for pyzk 0.9, swapped into its ZK class when the agent connects. The installed package is never edited: it stays
pinned by hash (requirements.txt), and pyzk has had no release since 2019.

ZK.__recieve_raw_data (sic) collects the rest of a transfer in a loop that ends only once every expected byte has
arrived. When the device closes the connection cleanly mid-transfer, recv() returns b'' at once, every time: the loop
never ends, and the run spins at 100% CPU until Task Scheduler kills it ten minutes later. The replacement raises
ZKNetworkError instead, so the read fails like any other lost connection and the next run tries again.

The other recv() calls behind what the agent may use (device.ALLOWED_CALLS) end by themselves on an empty read: an
invalid packet, a short unpack or a refused chunk raises. enroll_user and live_capture loop too, but the agent never
calls them.
"""

# Inside class ZK, pyzk's self.__recieve_raw_data and self.__sock are these names (Python's name mangling).
METHOD = "_ZK__recieve_raw_data"
SOCKET = "_ZK__sock"
_APPLIED = "_bhabaghure_fixed"


def apply(zk_class=None, network_error=None) -> None:
    """Once per process. With no arguments, pyzk's own ZK and ZKNetworkError (the tests pass stand-ins)."""
    if zk_class is None:
        from zk import ZK
        from zk.exception import ZKNetworkError

        zk_class, network_error = ZK, ZKNetworkError
    if getattr(zk_class, _APPLIED, False):
        return
    if not callable(getattr(zk_class, METHOD, None)):
        # Only a different pyzk gets here, and the build's tests run against the pinned one first.
        raise RuntimeError(f"This pyzk has no {METHOD}; check pyzk_fixes.py against it.")

    def receive_raw_data(self, size):
        chunks = []
        while size > 0:
            chunk = getattr(self, SOCKET).recv(size)
            if not chunk:
                raise network_error("the device closed the connection")
            chunks.append(chunk)
            size -= len(chunk)
        return b"".join(chunks)

    setattr(zk_class, METHOD, receive_raw_data)
    setattr(zk_class, _APPLIED, True)
