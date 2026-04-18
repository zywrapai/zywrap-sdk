from setuptools import setup, find_packages

setup(
    name="zywrap",
    version="1.0.2",
    description="The official Python SDK for the Zywrap AI API.",
    long_description=open("README.md", "r", encoding="utf-8").read(),
    long_description_content_type="text/markdown",
    author="Zywrap",
    url="https://github.com/zywrapai/zywrap-sdk",
    packages=find_packages(exclude=["examples*"]),
    install_requires=[
        "requests>=2.25.1",
    ],
    python_requires=">=3.8",
    keywords=["zywrap", "ai", "llm", "proxy"],
    classifiers=[
        "Development Status :: 5 - Production/Stable",
        "Intended Audience :: Developers",
        "License :: OSI Approved :: MIT License",
        "Programming Language :: Python :: 3",
        "Programming Language :: Python :: 3.8",
        "Programming Language :: Python :: 3.9",
        "Programming Language :: Python :: 3.10",
        "Programming Language :: Python :: 3.11",
    ],
)